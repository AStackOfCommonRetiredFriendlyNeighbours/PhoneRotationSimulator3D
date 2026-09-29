package ch.csbern.m169.phonetracker

import android.util.Log
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

/**
 * Sends one small JSON POST per tracked pose to api/arcore_ingest.php on
 * the server -- plain http://, no TLS (see arcore_ingest.php's own comment
 * on why that's fine for a native app's own networking, unlike a browser
 * page).
 *
 * Runs on a single dedicated worker thread with a small bounded queue: if
 * the network falls behind, older un-sent poses are simply dropped instead
 * of piling up latency. A slightly-stale pose isn't worth keeping once a
 * fresher one exists -- "least amount of delay" was the whole point.
 */
class PoseSender(private val uniqueId: String) {

    @Volatile
    private var target: String? = null

    // Real, confirmed-by-the-server counters -- unlike the old behavior of
    // reporting "sending" the instant a send was *attempted*, these only
    // move once postPose() actually gets a response (or fails to). This is
    // what let a request silently vanish into the network while the phone's
    // own screen claimed everything was fine.
    private val sentCount = AtomicInteger(0)
    private val failedCount = AtomicInteger(0)
    @Volatile
    private var lastError: String? = null

    private val queue = LinkedBlockingQueue<Runnable>(MAX_QUEUED)
    private val executor = ThreadPoolExecutor(
        1, 1, 0L, TimeUnit.MILLISECONDS, queue
    ) { r -> Thread(r, "pose-sender") }

    fun setTarget(ipPort: String) {
        target = ipPort.trim().trimEnd('/')
        sentCount.set(0)
        failedCount.set(0)
        lastError = null
    }

    /** (confirmed sent, confirmed failed, most recent failure reason) */
    fun stats(): Triple<Int, Int, String?> = Triple(sentCount.get(), failedCount.get(), lastError)

    fun send(t: Double, qx: Float, qy: Float, qz: Float, qw: Float, px: Float, py: Float, pz: Float) {
        val host = target ?: return
        if (queue.size >= MAX_QUEUED) {
            // Falling behind -- drop this sample instead of growing the queue.
            return
        }
        executor.execute { postPose(host, t, qx, qy, qz, qw, px, py, pz) }
    }

    private fun postPose(
        host: String,
        t: Double,
        qx: Float, qy: Float, qz: Float, qw: Float,
        px: Float, py: Float, pz: Float
    ) {
        try {
            val url = URL("http://$host/api/arcore_ingest.php")
            val conn = (url.openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                doOutput = true
                connectTimeout = 2000
                readTimeout = 2000
                setRequestProperty("Content-Type", "application/json")
            }
            val body = "{\"uniqueId\":\"$uniqueId\",\"t\":$t," +
                "\"qx\":$qx,\"qy\":$qy,\"qz\":$qz,\"qw\":$qw," +
                "\"px\":$px,\"py\":$py,\"pz\":$pz}"
            OutputStreamWriter(conn.outputStream).use { it.write(body) }
            val code = conn.responseCode
            conn.disconnect()
            if (code in 200..299) {
                sentCount.incrementAndGet()
            } else {
                failedCount.incrementAndGet()
                lastError = "server said HTTP $code"
                Log.w(TAG, "Pose POST rejected: HTTP $code")
            }
        } catch (e: Exception) {
            // Transient network hiccup, wrong IP, server not running yet, etc.
            // -- not fatal, the next frame's sample will just try again, but
            // now it's actually visible on screen instead of silently eaten.
            failedCount.incrementAndGet()
            lastError = e.message ?: e.javaClass.simpleName
            Log.w(TAG, "Pose POST failed: ${e.message}")
        }
    }

    fun shutdown() {
        executor.shutdownNow()
    }

    companion object {
        private const val TAG = "PoseSender"
        private const val MAX_QUEUED = 3
    }
}
