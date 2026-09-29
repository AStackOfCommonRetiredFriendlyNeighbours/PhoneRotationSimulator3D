plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "ch.csbern.m169.phonetracker"
    compileSdk = 34

    defaultConfig {
        applicationId = "ch.csbern.m169.phonetracker"
        // AR Required apps must declare minSdk >= 24 (ARCore's own requirement).
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    // ARCore (Google Play Services for AR) -- gives us camera.pose each
    // frame: real visual-inertial 6DoF tracking, the thing phyphox can't do.
    implementation("com.google.ar:core:1.56.0")

    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.core:core-ktx:1.13.1")
}
