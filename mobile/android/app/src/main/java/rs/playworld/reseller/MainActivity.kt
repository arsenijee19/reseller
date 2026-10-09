package rs.playworld.reseller

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.text.InputFilter
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.inputmethod.EditorInfo
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.security.KeyStore
import java.security.SecureRandom
import java.util.Base64
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlin.concurrent.thread

class MainActivity : android.app.Activity() {
    private lateinit var webView: WebView
    private lateinit var progressBar: ProgressBar
    private lateinit var root: FrameLayout
    private lateinit var activationView: View
    private lateinit var loadingView: LinearLayout
    private lateinit var errorView: LinearLayout
    private lateinit var codeInput: EditText
    private lateinit var twoFactorInput: EditText
    private lateinit var activationButton: Button
    private lateinit var messageView: TextView
    private var checkingSession = false
    private var sessionLoadedAt = 0L
    private var reloadPortalAfterSession = false
    private var appResumed = false
    private val sessionHandler = Handler(Looper.getMainLooper())
    private val sessionRefreshTask = object : Runnable {
        override fun run() {
            if (appResumed && webView.visibility == View.VISIBLE) refreshDeviceSession()
            if (appResumed) sessionHandler.postDelayed(this, SESSION_REFRESH_MS)
        }
    }

    @SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.TRANSPARENT
        window.navigationBarColor = Color.TRANSPARENT
        WindowCompat.setDecorFitsSystemWindows(window, false)

        root = FrameLayout(this)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            view.setPadding(0, bars.top, 0, maxOf(bars.bottom, ime.bottom))
            insets
        }
        val content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setBackgroundColor(WHITE) }
        progressBar = ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply {
            isIndeterminate = false; max = 100; progress = 0
            progressTintList = android.content.res.ColorStateList.valueOf(BLUE)
            progressBackgroundTintList = android.content.res.ColorStateList.valueOf(BG)
            visibility = View.GONE
        }
        content.addView(progressBar, LinearLayout.LayoutParams(-1, dp(3)))
        webView = WebView(this).apply {
            setBackgroundColor(WHITE)
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
            addJavascriptInterface(AppBridge(), "PlayWorldNative")
            webViewClient = PortalWebViewClient()
            webChromeClient = object : WebChromeClient() {
                override fun onProgressChanged(view: WebView?, newProgress: Int) {
                    progressBar.progress = newProgress
                    progressBar.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
                }
            }
        }
        CookieManager.getInstance().apply { setAcceptCookie(true); setAcceptThirdPartyCookies(webView, false) }
        content.addView(webView, LinearLayout.LayoutParams(-1, 0, 1f))
        root.addView(content)
        activationView = createActivationView()
        root.addView(activationView, FrameLayout.LayoutParams(-1, -1))
        loadingView = createLoadingView()
        root.addView(loadingView, FrameLayout.LayoutParams(-1, -1))
        errorView = createConnectionErrorView()
        root.addView(errorView, FrameLayout.LayoutParams(-1, -1))
        errorView.visibility = View.GONE
        setContentView(root)
        WindowInsetsControllerCompat(window, root).apply { isAppearanceLightStatusBars = true; isAppearanceLightNavigationBars = true }
        webView.visibility = View.GONE
        activationView.visibility = View.GONE
        loadingView.visibility = View.VISIBLE
        restoreOrActivate()
    }

    override fun onResume() {
        super.onResume()
        appResumed = true
        sessionHandler.removeCallbacks(sessionRefreshTask)
        sessionHandler.postDelayed(sessionRefreshTask, SESSION_REFRESH_MS)
        if (::root.isInitialized && ::webView.isInitialized && webView.visibility == View.VISIBLE &&
            System.currentTimeMillis() - sessionLoadedAt > SESSION_REFRESH_MS) refreshDeviceSession()
    }

    override fun onPause() {
        appResumed = false
        sessionHandler.removeCallbacks(sessionRefreshTask)
        super.onPause()
    }

    @Deprecated("Deprecated in Android; retained for supported devices")
    override fun onBackPressed() { if (webView.visibility == View.VISIBLE && webView.canGoBack()) webView.goBack() else super.onBackPressed() }

    override fun onDestroy() { if (::webView.isInitialized) { webView.stopLoading(); webView.destroy() }; super.onDestroy() }

    private fun createActivationView(): View {
        val page = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL
            setPadding(dp(24), dp(20), dp(24), dp(24)); background = gradient(BG, WHITE, 24)
        }
        val scroller = ScrollView(this).apply { isFillViewport = true; clipToPadding = false }
        val stack = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL }
        val brand = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL; setPadding(0, dp(8), 0, dp(30)) }
        val logo = TextView(this).apply {
            text = "🎮"; textSize = 25f; gravity = Gravity.CENTER
            background = gradient(BLUE, PURPLE, 18); setTextColor(Color.WHITE)
        }
        brand.addView(logo, LinearLayout.LayoutParams(dp(50), dp(50)))
        val brandText = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(12), 0, 0, 0) }
        brandText.addView(label("PlayWorld.rs", 18f, INK, true))
        brandText.addView(label("Reseller aplikacija", 13f, MUTED, false))
        brand.addView(brandText)
        stack.addView(brand, LinearLayout.LayoutParams(-1, dp(68)))

        val card = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL; setPadding(dp(22), dp(22), dp(22), dp(22))
            background = rounded(WHITE, dp(22), STROKE); elevation = dp(5).toFloat()
        }
        val emblem = TextView(this).apply {
            text = "✦"; textSize = 20f; gravity = Gravity.CENTER; setTextColor(BLUE)
            background = rounded(Color.rgb(228, 237, 255), dp(14))
        }
        card.addView(emblem, LinearLayout.LayoutParams(dp(48), dp(48)))
        card.addView(label("Aktivirajte ovaj uređaj", 22f, INK, true).apply { setPadding(0, dp(15), 0, dp(6)) })
        card.addView(label("Unesite jednokratni kod koji Vam je izdao administrator. Kod se koristi samo jednom.", 14f, MUTED, false).apply { setLineSpacing(dp(3).toFloat(), 1f); setPadding(0, 0, 0, dp(19)) })
        card.addView(fieldLabel("Aktivacioni kod"))
        codeInput = editField("ABCD-EFGH-JKLM", InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS)
        codeInput.filters = arrayOf(InputFilter.LengthFilter(14), InputFilter.AllCaps())
        codeInput.letterSpacing = .12f
        card.addView(codeInput, matchWrap(dp(54)))
        card.addView(fieldLabel("Authenticator kod · ako je uključen 2FA"))
        twoFactorInput = editField("6 cifara", InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD)
        twoFactorInput.filters = arrayOf(InputFilter.LengthFilter(6))
        twoFactorInput.letterSpacing = .2f
        card.addView(twoFactorInput, matchWrap(dp(54)))
        activationButton = Button(this).apply {
            text = "Aktiviraj uređaj"; isAllCaps = false; setTextColor(Color.WHITE); textSize = 15f
            typeface = Typeface.DEFAULT_BOLD; background = gradient(BLUE, PURPLE, 14)
            setOnClickListener { activateDevice() }
        }
        val buttonParams = matchWrap(dp(54)); buttonParams.topMargin = dp(18)
        card.addView(activationButton, buttonParams)
        messageView = label("", 13f, MUTED, false).apply { setPadding(0, dp(13), 0, 0); gravity = Gravity.CENTER }
        card.addView(messageView)
        stack.addView(card, LinearLayout.LayoutParams(-1, -2))
        stack.addView(label("Pristup ostaje aktivan na ovom uređaju dok ne uklonite aplikaciju, ne odjavite se ili administrator ne opozove uređaj.", 12f, MUTED, false).apply { gravity = Gravity.CENTER; setPadding(dp(8), dp(16), dp(8), 0) })
        scroller.addView(stack)
        page.addView(scroller, LinearLayout.LayoutParams(-1, 0, 1f))
        page.addView(label("www.playworld.rs", 12f, MUTED, false).apply { gravity = Gravity.CENTER; setPadding(0, dp(12), 0, 0) })
        return page
    }

    private fun createLoadingView() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(32), dp(24), dp(32), dp(24)); background = gradient(BG, WHITE, 24)
        addView(TextView(this@MainActivity).apply { text = "🎮"; textSize = 40f; gravity = Gravity.CENTER })
        addView(label("Bezbedno povezivanje", 19f, INK, true).apply { gravity = Gravity.CENTER; setPadding(0, dp(14), 0, dp(6)) })
        addView(label("Proveravamo prijavu ovog uređaja…", 14f, MUTED, false).apply { gravity = Gravity.CENTER })
        addView(ProgressBar(this@MainActivity).apply { indeterminateTintList = android.content.res.ColorStateList.valueOf(BLUE) }, LinearLayout.LayoutParams(dp(38), dp(38)).apply { topMargin = dp(22) })
    }

    private fun createConnectionErrorView() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(32), dp(24), dp(32), dp(24)); background = gradient(BG, WHITE, 24)
        addView(label("Portal trenutno nije dostupan", 20f, INK, true).apply { gravity = Gravity.CENTER })
        addView(label("Proverite internet vezu. Vaša aktivacija je sačuvana i ne morate ponovo da unosite kod.", 14f, MUTED, false).apply { gravity = Gravity.CENTER; setPadding(0, dp(10), 0, dp(20)) })
        addView(Button(this@MainActivity).apply {
            text = "Pokušajte ponovo"; isAllCaps = false
            setOnClickListener { errorView.visibility = View.GONE; loadingView.visibility = View.VISIBLE; restoreOrActivate() }
        })
    }

    private fun restoreOrActivate() {
        val saved = DeviceVault.read(this)
        if (saved == null) { showActivation(); return }
        loadingView.visibility = View.VISIBLE
        api("session", JSONObject().put("device_id", saved.first).put("device_token", saved.second)) { result, error ->
            if (result == null || (result.optInt("http_status") != 401 && error != null)) {
                showConnectionError(error)
            } else if (error != null || result.optBoolean("ok") != true) {
                DeviceVault.clear(this)
                clearPortalCookies { showActivation("Aktivacija nije potvrđena ili je uređaj opozvan. Proverite kod ili zatražite novi od administratora.") }
            } else establishPortalSession(result) {}
        }
    }

    private fun activateDevice() {
        val code = codeInput.text.toString().trim().uppercase().replace("[^A-Z0-9]".toRegex(), "")
        val totp = twoFactorInput.text.toString().trim()
        if (code.length != 12) { showActivation("Aktivacioni kod mora imati 12 znakova."); return }
        val id = deviceId()
        val secret = newDeviceSecret()
        try { DeviceVault.write(this, id, secret) } catch (_: Exception) { showActivation("Ne možemo bezbedno sačuvati prijavu na ovom uređaju."); return }
        setBusy(true)
        val body = JSONObject().put("code", code).put("device_id", id).put("device_token", secret).put("platform", "android")
            .put("device_name", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}".take(120))
        if (totp.isNotEmpty()) body.put("two_factor_code", totp)
        api("activate", body) { result, error ->
            if (error != null || result?.optBoolean("ok") != true) {
                recoverActivation(id, secret, error ?: "Aktivacija nije uspela. Proverite kod i pokušajte ponovo.")
                return@api
            }
            setBusy(false)
            establishPortalSession(result) {}
        }
    }

    private fun recoverActivation(deviceId: String, secret: String, activationError: String) {
        api("session", JSONObject().put("device_id", deviceId).put("device_token", secret)) { recovered, recoveryError ->
            setBusy(false)
            if (recovered?.optBoolean("ok") == true && recoveryError == null) {
                establishPortalSession(recovered) {}
            } else if (recovered?.optInt("http_status") == 401) {
                DeviceVault.clear(this)
                twoFactorInput.text.clear()
                showActivation(activationError)
            } else {
                showConnectionError("Veza je prekinuta tokom aktivacije. Kod je sačuvan na ovom uređaju; pokušajte ponovo da se povežete.")
            }
        }
    }

    private fun refreshDeviceSession() {
        if (checkingSession) return
        val saved = DeviceVault.read(this) ?: return
        checkingSession = true
        api("session", JSONObject().put("device_id", saved.first).put("device_token", saved.second)) { result, error ->
            checkingSession = false
            if (result == null || (result.optInt("http_status") != 401 && error != null)) {
                showConnectionError(error)
            } else if (error != null || result.optBoolean("ok") != true) {
                DeviceVault.clear(this)
                clearPortalCookies { showActivation("Pristup ovom uređaju je opozvan. Unesite novi jednokratni kod.") }
            } else establishPortalSession(result) { sessionLoadedAt = System.currentTimeMillis() }
        }
    }

    private fun establishPortalSession(result: JSONObject, after: () -> Unit) {
        CookieManager.getInstance().flush()
        webView.post {
            webView.visibility = View.VISIBLE
            activationView.visibility = View.GONE
            loadingView.visibility = View.GONE
            if (reloadPortalAfterSession || webView.url.isNullOrBlank() || webView.url == "about:blank") {
                reloadPortalAfterSession = false
                webView.loadUrl(PORTAL_URL)
            }
            sessionLoadedAt = System.currentTimeMillis()
            after()
        }
    }

    private fun api(action: String, body: JSONObject, done: (JSONObject?, String?) -> Unit) {
        thread(name = "portal-device-auth") {
            var connection: HttpURLConnection? = null
            try {
                val conn = (URL("$API_URL?action=$action").openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"; connectTimeout = 12000; readTimeout = 16000; doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                    setRequestProperty("Accept", "application/json")
                    setRequestProperty("Origin", PORTAL_ORIGIN)
                    setRequestProperty("User-Agent", "PlayWorldAndroid/${android.os.Build.VERSION.RELEASE}")
                    setRequestProperty("X-Requested-With", "PlayWorldResellerApp")
                }
                connection = conn
                conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
                val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
                val payload = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
                val json = JSONObject(payload.ifBlank { "{}" })
                json.put("http_status", conn.responseCode)
                val message = if (conn.responseCode in 200..299 && json.optBoolean("ok")) null else json.optString("error", "Povezivanje nije uspelo (${conn.responseCode}).")
                val response = json
                val cookies = conn.headerFields.entries.flatMap { entry ->
                    if (entry.key?.equals("Set-Cookie", ignoreCase = true) == true) entry.value.orEmpty() else emptyList()
                }
                fun continueAfterCookies(index: Int) {
                    if (index >= cookies.size) runOnUiThread { done(response, message) }
                    else CookieManager.getInstance().setCookie(PORTAL_ORIGIN, cookies[index].substringBefore(';')) { continueAfterCookies(index + 1) }
                }
                continueAfterCookies(0)
            } catch (_: Exception) {
                runOnUiThread { done(null, "Ne možemo da se povežemo sa portalom. Proverite internet i pokušajte ponovo.") }
            } finally { connection?.disconnect() }
        }
    }

    private fun showActivation(message: String? = null) {
        runOnUiThread {
            webView.visibility = View.GONE; loadingView.visibility = View.GONE; errorView.visibility = View.GONE; activationView.visibility = View.VISIBLE
            messageView.text = message.orEmpty(); messageView.setTextColor(if (message.isNullOrEmpty()) MUTED else RED)
            progressBar.visibility = View.GONE
            WindowInsetsControllerCompat(window, root).apply { isAppearanceLightStatusBars = true; isAppearanceLightNavigationBars = true }
        }
    }

    private fun setBusy(busy: Boolean) {
        activationButton.isEnabled = !busy
        activationButton.text = if (busy) "Proveravam kod…" else "Aktiviraj uređaj"
        activationButton.alpha = if (busy) .72f else 1f
        messageView.text = if (busy) "Kod se bezbedno proverava. Sačekajte trenutak…" else ""
        messageView.setTextColor(MUTED)
    }

    private fun revokeAndShowActivation() {
        val saved = DeviceVault.read(this)
        DeviceVault.clear(this)
        CookieManager.getInstance().removeAllCookies {
            CookieManager.getInstance().flush()
            runOnUiThread { webView.stopLoading(); webView.loadUrl("about:blank"); showActivation("Odjavljeni ste sa ovog uređaja.") }
        }
        if (saved != null) api("logout", JSONObject().put("device_id", saved.first).put("device_token", saved.second)) { _, _ -> }
    }

    private fun clearPortalCookies(done: () -> Unit) {
        CookieManager.getInstance().removeAllCookies { CookieManager.getInstance().flush(); runOnUiThread(done) }
    }

    private fun deviceId(): String {
        val prefs = getSharedPreferences(PREFS, MODE_PRIVATE)
        val current = prefs.getString(DEVICE_ID, null)
        if (current != null) return current
        return UUID.randomUUID().toString().also { prefs.edit().putString(DEVICE_ID, it).apply() }
    }

    private fun newDeviceSecret(): String {
        val bytes = ByteArray(32).also { SecureRandom().nextBytes(it) }
        return Base64.getUrlEncoder().withoutPadding().encodeToString(bytes)
    }

    private inner class AppBridge {
        @JavascriptInterface fun logout() { runOnUiThread { revokeAndShowActivation() } }
    }

    private inner class PortalWebViewClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val uri = request.url
            if (uri.scheme == "https" && uri.host == PORTAL_HOST && uri.path != "/admin.html") return false
            openExternalLink(uri); return true
        }
        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) { super.onReceivedError(view, request, error); if (request.isForMainFrame) showConnectionError() }
        override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: android.net.http.SslError) { handler.cancel(); showConnectionError() }
    }

    private fun openExternalLink(uri: Uri) {
        if (uri.scheme !in setOf("https", "http", "mailto", "tel")) return
        try { startActivity(Intent(Intent.ACTION_VIEW, uri)) } catch (_: ActivityNotFoundException) { }
    }

    private fun showConnectionError() = showConnectionError(null)
    private fun showConnectionError(message: String?) {
        runOnUiThread {
            reloadPortalAfterSession = true
            webView.visibility = View.GONE; activationView.visibility = View.GONE; loadingView.visibility = View.GONE; errorView.visibility = View.VISIBLE
            (errorView.getChildAt(1) as? TextView)?.text = message ?: "Proverite internet vezu. Vaša aktivacija je sačuvana i ne morate ponovo da unosite kod."
            progressBar.visibility = View.GONE
        }
    }
    private fun label(value: String, size: Float, color: Int, bold: Boolean) = TextView(this).apply { text = value; textSize = size; setTextColor(color); if (bold) setTypeface(typeface, Typeface.BOLD) }
    private fun fieldLabel(value: String) = label(value, 13f, INK, true).apply { setPadding(0, dp(16), 0, dp(7)) }
    private fun editField(hintText: String, inputType: Int) = EditText(this).apply {
        hint = hintText; textSize = 15f; setTextColor(INK); setHintTextColor(MUTED); this.inputType = inputType
        imeOptions = EditorInfo.IME_ACTION_NEXT; setSingleLine(true); setPadding(dp(15), 0, dp(15), 0); background = rounded(WHITE, dp(13), STROKE)
    }
    private fun matchWrap(height: Int) = LinearLayout.LayoutParams(-1, height)
    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()
    private fun rounded(color: Int, radius: Int, stroke: Int? = null) = GradientDrawable().apply { setColor(color); cornerRadius = radius.toFloat(); if (stroke != null) setStroke(dp(1), stroke) }
    private fun gradient(start: Int, end: Int, radius: Int) = GradientDrawable(GradientDrawable.Orientation.TL_BR, intArrayOf(start, end)).apply { cornerRadius = dp(radius).toFloat() }

    companion object {
        private const val PORTAL_HOST = "reseller.psigre.rs"
        private const val PORTAL_ORIGIN = "https://reseller.psigre.rs"
        private const val PORTAL_URL = "$PORTAL_ORIGIN/"
        private const val API_URL = "$PORTAL_ORIGIN/api/device_auth.php"
        private const val PREFS = "reseller_device"
        private const val DEVICE_ID = "device_id"
        private const val SESSION_REFRESH_MS = 45L * 60L * 1000L
        private const val BLUE = 0xFF2563EB.toInt()
        private const val PURPLE = 0xFF4F46E5.toInt()
        private const val BG = 0xFFEEF2F9.toInt()
        private const val WHITE = Color.WHITE
        private const val INK = 0xFF0F172A.toInt()
        private const val MUTED = 0xFF5B6577.toInt()
        private const val STROKE = 0xFFD9E2F0.toInt()
        private const val RED = 0xFFB42318.toInt()
    }
}

private object DeviceVault {
    private const val KEY_ALIAS = "playworld_reseller_device_key"
    private const val PREFS = "reseller_device"
    private const val SECRET = "encrypted_device_secret"
    private const val IV = "device_secret_iv"

    fun write(activity: android.app.Activity, deviceId: String, secret: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, getKey())
        val encrypted = cipher.doFinal(secret.toByteArray(Charsets.UTF_8))
        activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit()
            .putString("device_id", deviceId)
            .putString(SECRET, Base64.getEncoder().encodeToString(encrypted))
            .putString(IV, Base64.getEncoder().encodeToString(cipher.iv)).apply()
    }

    fun read(activity: android.app.Activity): Pair<String, String>? {
        return try {
            val prefs = activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE)
            val id = prefs.getString("device_id", null) ?: return null
            val encrypted = Base64.getDecoder().decode(prefs.getString(SECRET, null) ?: return null)
            val iv = Base64.getDecoder().decode(prefs.getString(IV, null) ?: return null)
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, getKey(), GCMParameterSpec(128, iv))
            id to String(cipher.doFinal(encrypted), Charsets.UTF_8)
        } catch (_: Exception) { clear(activity); null }
    }

    fun clear(activity: android.app.Activity) {
        activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit().remove(SECRET).remove(IV).apply()
        try { KeyStore.getInstance("AndroidKeyStore").apply { load(null); if (containsAlias(KEY_ALIAS)) deleteEntry(KEY_ALIAS) } } catch (_: Exception) { }
    }

    private fun getKey(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).setRandomizedEncryptionRequired(true).build())
        return generator.generateKey()
    }
}
