package com.veggietokri.app.push

import android.content.Context
import android.net.Uri

object PushDeepLink {
    fun storePendingUrl(context: Context, url: String) {
        val host = context.getString(R.string.allowed_web_host)
        val uri = Uri.parse(url)
        if (uri.host != null && uri.host != host) return
        context.getSharedPreferences("push", Context.MODE_PRIVATE)
            .edit()
            .putString("pending_url", url)
            .apply()
    }
}
