package rs.playworld.reseller

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.webkit.CookieManager
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat

class MainActivity : android.app.Activity() {
    private lateinit var webView: WebView
    private lateinit var progressBar: ProgressBar
    private lateinit var errorPanel: LinearLayout

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.TRANSPARENT
        window.navigationBarColor = Color.TRANSPARENT
        WindowCompat.setDecorFitsSystemWindows(window, false)

        val root = FrameLayout(this)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            view.setPadding(0, systemBars.top, 0, maxOf(systemBars.bottom, ime.bottom))
            insets
        }
        WindowInsetsControllerCompat(window, root).apply {
            isAppearanceLightStatusBars = true
            isAppearanceLightNavigationBars = true
        }
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(Color.WHITE)
        }

        progressBar = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply {
            isIndeterminate = false
            max = 100
            progress = 0
            progressTintList = android.content.res.ColorStateList.valueOf(Color.rgb(49, 93, 244))
            progressBackgroundTintList = android.content.res.ColorStateList.valueOf(Color.rgb(238, 242, 249))
        }
        content.addView(progressBar, LinearLayout.LayoutParams(-1, dp(3)))

        webView = WebView(this).apply {
            setBackgroundColor(Color.WHITE)
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.databaseEnabled = true
            settings.allowFileAccess = false
            settings.allowContentAccess = false
            settings.mixedContentMode = android.webkit.WebSettings.MIXED_CONTENT_NEVER_ALLOW
            settings.javaScriptCanOpenWindowsAutomatically = false
            settings.setSupportMultipleWindows(false)
            settings.setSupportZoom(false)
            settings.cacheMode = android.webkit.WebSettings.LOAD_DEFAULT
            webViewClient = PortalWebViewClient()
            webChromeClient = object : WebChromeClient() {
                override fun onProgressChanged(view: WebView?, newProgress: Int) {
                    progressBar.progress = newProgress
                    progressBar.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
                }
            }
        }
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, false)
        }
        content.addView(webView, LinearLayout.LayoutParams(-1, 0, 1f))
        root.addView(content)

        errorPanel = createErrorPanel()
        root.addView(errorPanel, FrameLayout.LayoutParams(-1, -1))
        errorPanel.visibility = View.GONE
        setContentView(root)

        if (savedInstanceState == null) {
            webView.loadUrl(PORTAL_URL)
        } else {
            webView.restoreState(savedInstanceState)
        }
    }

    @Deprecated("Deprecated in Android; retained for older supported devices")
    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView.saveState(outState)
        super.onSaveInstanceState(outState)
    }

    override fun onDestroy() {
        webView.stopLoading()
        webView.destroy()
        super.onDestroy()
    }

    private fun createErrorPanel(): LinearLayout = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.CENTER
        setPadding(dp(32), dp(24), dp(32), dp(24))
        setBackgroundColor(Color.rgb(238, 242, 249))

        addView(TextView(this@MainActivity).apply {
            text = "Nema veze sa portalom"
            textSize = 20f
            setTextColor(Color.rgb(15, 23, 42))
            gravity = Gravity.CENTER
            setTypeface(typeface, android.graphics.Typeface.BOLD)
        })
        addView(TextView(this@MainActivity).apply {
            text = "Proverite internet vezu i pokušajte ponovo. Vaši podaci za prijavu nisu izgubljeni."
            textSize = 15f
            setTextColor(Color.rgb(91, 101, 119))
            gravity = Gravity.CENTER
            setPadding(0, dp(10), 0, dp(20))
        })
        addView(Button(this@MainActivity).apply {
            text = "Pokušajte ponovo"
            isAllCaps = false
            setOnClickListener {
                errorPanel.visibility = View.GONE
                webView.loadUrl(PORTAL_URL)
            }
        })
    }

    private inner class PortalWebViewClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val uri = request.url
            if (uri.scheme == "https" && uri.host == PORTAL_HOST && uri.path != "/admin.html") return false
            openExternalLink(uri)
            return true
        }

        override fun onReceivedError(
            view: WebView,
            request: WebResourceRequest,
            error: WebResourceError
        ) {
            super.onReceivedError(view, request, error)
            if (request.isForMainFrame) showConnectionError()
        }

        override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: android.net.http.SslError) {
            handler.cancel()
            showConnectionError()
        }
    }

    private fun openExternalLink(uri: Uri) {
        if (uri.scheme !in setOf("https", "http", "mailto", "tel")) return
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        } catch (_: ActivityNotFoundException) {
            // Ignore links that have no installed handler.
        }
    }

    private fun showConnectionError() {
        runOnUiThread {
            errorPanel.visibility = View.VISIBLE
            progressBar.visibility = View.GONE
        }
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    companion object {
        private const val PORTAL_HOST = "reseller.psigre.rs"
        private const val PORTAL_URL = "https://reseller.psigre.rs/"
    }
}
