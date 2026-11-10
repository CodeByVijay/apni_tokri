package com.veggietokri.app.push

import android.content.Context

/** Placeholder — implement HTTPS POST to /ajax/push-register.php with CSRF + session cookie. */
object PushRegistrationSync {
    fun enqueue(context: Context, token: String) {
        // WorkManager job: platform=android, token, app version
    }
}
