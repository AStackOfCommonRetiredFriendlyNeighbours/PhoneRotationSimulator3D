package ch.csbern.m169.phonetracker

import android.Manifest
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.opengl.GLES20
import android.opengl.GLSurfaceView
import android.os.Bundle
import android.util.Log
import android.view.WindowManager
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.google.ar.core.ArCoreApk
import com.google.ar.core.Config
import com.google.ar.core.Session
import com.google.ar.core.TrackingState
import com.google.ar.core.exceptions.CameraNotAvailableException
import com.google.ar.core.exceptions.UnavailableApkTooOldException
import com.google.ar.core.exceptions.UnavailableArcoreNotInstalledException
import com.google.ar.core.exceptions.UnavailableDeviceNotCompatibleException
import com.google.ar.core.exceptions.UnavailableSdkTooOldException
import com.google.ar.core.exceptions.UnavailableUserDeclinedInstallationException
import java.util.UUID
import javax.microedition.khronos.egl.EGLConfig
import javax.microedition.khronos.opengles.GL10

/**
 * The whole app: an ARCore session drives a GLSurfaceView (camera
 * passthrough only -- see BackgroundRenderer), and every frame where
 * tracking is good, the camera's pose (real metric position + rotation,
 * from ARCore's visual-inertial odometry -- fusing the camera with the
 * IMU, which is what makes this actually accurate unlike phyphox's
 * IMU-only attitude) gets handed to PoseSender to POST to the server.
 *
 * This deliberately mirrors the old phyphox workflow's shape (type an
 * IP:port, press start, watch it show up live on the viewer page) since
 * that part worked fine -- only the sensing/transport underneath changed.
 */
class MainActivity : AppCompatActivity(), GLSurfaceView.Renderer {

    private lateinit var surfaceView: GLSurfaceView
    private lateinit var ipField: EditText
    private lateinit var startStopButton: Button
    private lateinit var statusText: TextView
    private lateinit var prefs: SharedPreferences
    private lateinit var poseSender: PoseSender
    private lateinit var uniqueId: String

    private var session: Session? = null
    private var installRequested = false

    // Whether the user has pressed "Start Tracking". Read/written from both
    // the UI thread (the button) and the GL thread (onDrawFrame) -- @Volatile
    // keeps that safe without needing a full lock for a single boolean flag.
    @Volatile
    private var tracking = false

    private val backgroundRenderer = BackgroundRenderer()
    private val sessionStartNanos = System.nanoTime()
    private var lastStatusUpdateNanos = 0L

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_main)

        prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
        uniqueId = prefs.getString(KEY_UNIQUE_ID, null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString(KEY_UNIQUE_ID, it).apply()
        }
        poseSender = PoseSender(uniqueId)

        surfaceView = findViewById(R.id.ar_surface_view)
        ipField = findViewById(R.id.ip_field)
        startStopButton = findViewById(R.id.start_stop_button)
        statusText = findViewById(R.id.status_text)

        ipField.setText(prefs.getString(KEY_SERVER_IP, ""))

        startStopButton.setOnClickListener {
            if (!tracking) {
                val ip = ipField.text.toString().trim()
                if (ip.isEmpty()) {
                    statusText.text = "Enter the server IP:port first"
                    return@setOnClickListener
                }
                prefs.edit().putString(KEY_SERVER_IP, ip).apply()
                poseSender.setTarget(ip)
                tracking = true
                startStopButton.text = "Stop Tracking"
                statusText.text = "Starting…"
            } else {
                tracking = false
                startStopButton.text = "Start Tracking"
                statusText.text = "Stopped"
            }
        }

        surfaceView.preserveEGLContextOnPause = true
        surfaceView.setEGLContextClientVersion(2)
        surfaceView.setEGLConfigChooser(8, 8, 8, 8, 16, 0)
        surfaceView.setRenderer(this)
        surfaceView.renderMode = GLSurfaceView.RENDERMODE_CONTINUOUSLY
    }

    override fun onResume() {
        super.onResume()

        if (session == null) {
            if (!ensureCameraPermission()) return
            if (!createSession()) return
        }

        try {
            session?.resume()
        } catch (e: CameraNotAvailableException) {
            statusText.text = "Camera not available"
            session = null
            return
        }
        surfaceView.onResume()
    }

    override fun onPause() {
        super.onPause()
        surfaceView.onPause()
        session?.pause()
    }

    override fun onDestroy() {
        super.onDestroy()
        session?.close()
        session = null
        poseSender.shutdown()
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == CAMERA_PERMISSION_CODE) {
            if (grantResults.isEmpty() || grantResults[0] != PackageManager.PERMISSION_GRANTED) {
                runOnUiThread { statusText.text = "Camera permission is required" }
            }
            // If it *was* granted, the next onResume (already about to run)
            // picks up session creation from there.
        }
    }

    private fun ensureCameraPermission(): Boolean {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
            == PackageManager.PERMISSION_GRANTED
        ) {
            return true
        }
        ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.CAMERA), CAMERA_PERMISSION_CODE)
        return false
    }

    private fun createSession(): Boolean {
        try {
            val installStatus = ArCoreApk.getInstance().requestInstall(this, !installRequested)
            if (installStatus == ArCoreApk.InstallStatus.INSTALL_REQUESTED) {
                installRequested = true
                return false // onResume runs again after the user returns from Play Store
            }

            session = Session(this).also { s ->
                val config = Config(s)
                // We only ever read camera.pose -- no need to spend cycles
                // on plane detection or light estimation.
                config.planeFindingMode = Config.PlaneFindingMode.DISABLED
                config.lightEstimationMode = Config.LightEstimationMode.DISABLED
                config.focusMode = Config.FocusMode.AUTO
                // BLOCKING (ARCore's own default) ties each GL frame to a
                // real new camera image instead of ever re-using a stale
                // one -- matters for pose freshness, not just rendering.
                config.updateMode = Config.UpdateMode.BLOCKING
                s.configure(config)
            }
            return true
        } catch (e: UnavailableArcoreNotInstalledException) {
            statusText.text = "Install Google Play Services for AR"
        } catch (e: UnavailableUserDeclinedInstallationException) {
            statusText.text = "ARCore install was declined"
        } catch (e: UnavailableApkTooOldException) {
            statusText.text = "Update Google Play Services for AR"
        } catch (e: UnavailableSdkTooOldException) {
            statusText.text = "This app needs updating"
        } catch (e: UnavailableDeviceNotCompatibleException) {
            statusText.text = "This device doesn't support ARCore"
        } catch (e: Exception) {
            statusText.text = "AR session failed: ${e.message}"
        }
        session = null
        return false
    }

    // --- GLSurfaceView.Renderer (all three run on the GL thread) ---

    override fun onSurfaceCreated(gl: GL10?, config: EGLConfig?) {
        GLES20.glClearColor(0f, 0f, 0f, 1f)
        backgroundRenderer.createOnGlThread()
        session?.setCameraTextureName(backgroundRenderer.textureId)
    }

    override fun onSurfaceChanged(gl: GL10?, width: Int, height: Int) {
        GLES20.glViewport(0, 0, width, height)
        @Suppress("DEPRECATION")
        session?.setDisplayGeometry(windowManager.defaultDisplay.rotation, width, height)
    }

    override fun onDrawFrame(gl: GL10?) {
        GLES20.glClear(GLES20.GL_COLOR_BUFFER_BIT or GLES20.GL_DEPTH_BUFFER_BIT)

        val currentSession = session ?: return
        try {
            currentSession.setCameraTextureName(backgroundRenderer.textureId)
            val frame = currentSession.update()
            backgroundRenderer.draw(frame)

            val camera = frame.camera
            if (tracking && camera.trackingState == TrackingState.TRACKING) {
                // displayOrientedPose, not the raw camera.pose: the raw pose
                // is fixed to the camera SENSOR's physical mounting, which on
                // most phones is landscape-native -- roughly a 90-degree twist
                // off from "held upright in portrait". displayOrientedPose
                // compensates for the screen's actual orientation (locked to
                // portrait here, see the manifest), which is what makes "tilt
                // the phone this way" match "the model tilts this way".
                val pose = camera.displayOrientedPose
                val t = pose.translation           // [x, y, z] meters, ARCore world frame
                val q = pose.rotationQuaternion     // [x, y, z, w]
                val elapsedSeconds = (System.nanoTime() - sessionStartNanos) / 1_000_000_000.0
                poseSender.send(elapsedSeconds, q[0], q[1], q[2], q[3], t[0], t[1], t[2])

                // Confirmed-by-the-server counts, not just "a send was attempted" --
                // this is what actually tells you whether the site *can* be
                // receiving data, instead of assuming it from the phone alone.
                val (sent, failed, err) = poseSender.stats()
                val statusMsg = when {
                    sent == 0 && failed == 0 -> "Tracking — connecting to ${ipField.text}…"
                    sent == 0 && failed > 0 -> "Not reaching server: ${err ?: "unknown error"}"
                    failed > 0 -> "Tracking — $sent sent, $failed failed (last: ${err ?: "?"})"
                    else -> "Tracking — $sent sent to ${ipField.text}"
                }
                maybeUpdateStatus(statusMsg)
            } else if (tracking) {
                maybeUpdateStatus("Tracking lost (${camera.trackingState}) — move the phone slowly")
            }
        } catch (e: CameraNotAvailableException) {
            Log.e(TAG, "Camera not available during onDrawFrame", e)
        } catch (e: Exception) {
            Log.e(TAG, "Error on AR frame update", e)
        }
    }

    /** Throttled to a few times a second so a ~30-60Hz frame loop isn't spamming runOnUiThread. */
    private fun maybeUpdateStatus(text: String) {
        val now = System.nanoTime()
        if (now - lastStatusUpdateNanos < 300_000_000L) return
        lastStatusUpdateNanos = now
        runOnUiThread { statusText.text = text }
    }

    companion object {
        private const val TAG = "PhoneTracker"
        private const val PREFS_NAME = "phone_tracker_prefs"
        private const val CAMERA_PERMISSION_CODE = 1001
        private const val KEY_SERVER_IP = "server_ip"
        private const val KEY_UNIQUE_ID = "unique_id"
    }
}
