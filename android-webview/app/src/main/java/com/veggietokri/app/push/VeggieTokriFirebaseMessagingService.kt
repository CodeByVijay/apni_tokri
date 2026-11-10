package com.veggietokri.app.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.veggietokri.app.R

/**
 * Native FCM — independent of WebView Push API.
 * Posts token to PHP backend (see ANDROID-SETUP.md).
 */
class VeggieTokriFirebaseMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        PushRegistrationSync.enqueue(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val title = message.notification?.title ?: getString(R.string.app_name)
        val body = message.notification?.body ?: ""
        val clickUrl = message.data["click_url"] ?: message.data["link"]
        showNotification(title, body, clickUrl)
    }

    private fun showNotification(title: String, body: String, clickUrl: String?) {
        val channelId = "orders"
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(channelId, "Orders", NotificationManager.IMPORTANCE_DEFAULT)
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
        val builder = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
        val notification = builder.build()
        NotificationManagerCompat.from(this).notify(System.currentTimeMillis().toInt(), notification)
        if (!clickUrl.isNullOrBlank()) {
            PushDeepLink.storePendingUrl(applicationContext, clickUrl)
        }
    }
}
