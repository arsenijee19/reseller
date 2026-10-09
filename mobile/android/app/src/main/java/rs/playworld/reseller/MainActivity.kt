package rs.playworld.reseller

import android.annotation.SuppressLint
import android.app.KeyguardManager
import android.os.Build
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.provider.Settings
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.text.InputFilter
import android.text.InputType
import android.view.Gravity
import android.view.animation.DecelerateInterpolator
import android.view.View
import android.view.WindowManager
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
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.animation.ValueAnimator
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
    private lateinit var activationButton: Button
    private lateinit var messageView: TextView
    private lateinit var connectionMessageView: TextView
    private lateinit var lockView: View
    private lateinit var lockMessageView: TextView
    private var locked = true
    private var authInProgress = false
    private var backgroundedAt = 0L
    private var lastSessionExpiredAt = 0L
    private val appPrefs by lazy { getSharedPreferences("app_settings", MODE_PRIVATE) }
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
        lockView = createLockView()
        root.addView(lockView, FrameLayout.LayoutParams(-1, -1))
        lockView.visibility = View.GONE
        applyScreenshotPolicy()
        animateIn(loadingView)
        restoreOrActivate()
    }

    override fun onResume() {
        super.onResume()
        appResumed = true
        applyScreenshotPolicy()
        sessionHandler.removeCallbacks(sessionRefreshTask)
        sessionHandler.postDelayed(sessionRefreshTask, SESSION_REFRESH_MS)
        if (!::root.isInitialized || !::webView.isInitialized) return
        val away = if (backgroundedAt > 0L) System.currentTimeMillis() - backgroundedAt else 0L
        if (lockRequired() && !locked && away > LOCK_TIMEOUT_MS) locked = true
        if (lockRequired() && locked) { showLock(); return }
        if (webView.visibility == View.VISIBLE) {
            // Coming back to the app re-validates the device, so a deactivated account or a revoked device is logged out right away.
            if (away > RESUME_CHECK_MS || System.currentTimeMillis() - sessionLoadedAt > SESSION_REFRESH_MS) refreshDeviceSession(silent = true)
        }
    }

    override fun onPause() {
        appResumed = false
        sessionHandler.removeCallbacks(sessionRefreshTask)
        if (!authInProgress) {
            backgroundedAt = System.currentTimeMillis()
            // Keep the app-switcher thumbnail blank while the lock is active.
            if (lockRequired()) applyScreenshotPolicy(forceSecure = true)
        }
        super.onPause()
    }

    @Deprecated("Deprecated in Android; retained for supported devices")
    override fun onBackPressed() { if (webView.visibility == View.VISIBLE && webView.canGoBack()) webView.goBack() else super.onBackPressed() }

    override fun onDestroy() { if (::webView.isInitialized) { webView.stopLoading(); webView.destroy() }; super.onDestroy() }

    private fun createActivationView(): View {
        val compact = resources.configuration.screenWidthDp <= 360
        val pageInset = if (compact) 16 else 24
        val page = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL
            setPadding(dp(pageInset), dp(16), dp(pageInset), dp(16)); background = gradient(BG, WHITE, 24)
        }
        val scroller = ScrollView(this).apply { isFillViewport = true; clipToPadding = false }
        val stack = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL }
        // Full PlayWorld Reseller logo, centered above the card.
        val logoSize = if (compact) 116 else 136
        stack.addView(logoMark(logoSize), LinearLayout.LayoutParams(dp(logoSize), dp(logoSize)).apply { topMargin = dp(8); bottomMargin = dp(22) })

        val card = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL; setPadding(dp(if (compact) 18 else 22), dp(22), dp(if (compact) 18 else 22), dp(22))
            background = rounded(WHITE, dp(18), STROKE); elevation = dp(3).toFloat()
        }
        val sectionHeading = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        val keyMark = ImageView(this).apply {
            setImageResource(R.drawable.ic_key_mark)
            background = rounded(Color.rgb(228, 237, 255), dp(12))
            setPadding(dp(12), dp(12), dp(12), dp(12))
            contentDescription = null
        }
        sectionHeading.addView(keyMark, LinearLayout.LayoutParams(dp(40), dp(40)))
        sectionHeading.addView(label("Prijava uređaja", 19f, INK, true).apply { setPadding(dp(12), 0, 0, 0) })
        card.addView(sectionHeading)
        card.addView(label("Povežite uređaj", 23f, INK, true).apply { setPadding(0, dp(18), 0, dp(6)); letterSpacing = -.025f })
        card.addView(label("Unesite jednokratni kod koji Vam je izdao administrator. Kod se koristi samo jednom.", 14f, MUTED, false).apply { setLineSpacing(dp(3).toFloat(), 1f); setPadding(0, 0, 0, dp(12)) })
        card.addView(fieldLabel("Aktivacioni kod"))
        codeInput = editField("ABCD-EFGH-JKLM", InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS)
        codeInput.filters = arrayOf(InputFilter.LengthFilter(14), InputFilter.AllCaps())
        codeInput.letterSpacing = .12f
        card.addView(codeInput, matchWrap(dp(50)))
        activationButton = primaryButton("Aktiviraj uređaj") { activateDevice() }
        val buttonParams = matchWrap(dp(48)); buttonParams.topMargin = dp(18)
        card.addView(activationButton, buttonParams)
        messageView = label("", 13f, MUTED, false).apply { setPadding(0, dp(12), 0, 0); gravity = Gravity.CENTER; setLineSpacing(dp(2).toFloat(), 1f) }
        card.addView(messageView)
        val width = minOf(dp(resources.configuration.screenWidthDp - pageInset * 2), dp(480))
        stack.addView(card, LinearLayout.LayoutParams(width, -2))
        stack.addView(label("Pristup ostaje aktivan na ovom uređaju dok ne uklonite aplikaciju, ne odjavite se ili administrator ne opozove uređaj.", 12f, MUTED, false).apply { gravity = Gravity.CENTER; setPadding(dp(8), dp(14), dp(8), dp(8)); setLineSpacing(dp(2).toFloat(), 1f) }, LinearLayout.LayoutParams(width, -2))
        scroller.addView(stack)
        page.addView(scroller, LinearLayout.LayoutParams(-1, 0, 1f))
        page.addView(label("www.playworld.rs", 12f, MUTED, false).apply { gravity = Gravity.CENTER; setPadding(0, dp(8), 0, 0) })
        return page
    }

    private fun createLoadingView() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(20), dp(20), dp(20), dp(20)); background = gradient(BG, WHITE, 24)
        val card = LinearLayout(this@MainActivity).apply {
            orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(24), dp(28), dp(24), dp(28))
            background = rounded(WHITE, dp(18), STROKE); elevation = dp(3).toFloat()
        }
        card.addView(logoMark(84), LinearLayout.LayoutParams(dp(84), dp(84)))
        card.addView(label("Bezbedno povezivanje", 19f, INK, true).apply { gravity = Gravity.CENTER; setPadding(0, dp(18), 0, dp(6)) })
        card.addView(label("Proveravamo prijavu ovog uređaja…", 14f, MUTED, false).apply { gravity = Gravity.CENTER })
        card.addView(ProgressBar(this@MainActivity).apply { indeterminateTintList = android.content.res.ColorStateList.valueOf(BLUE) }, LinearLayout.LayoutParams(dp(28), dp(28)).apply { topMargin = dp(20) })
        addView(card, LinearLayout.LayoutParams(minOf(dp(resources.configuration.screenWidthDp - 40), dp(420)), -2).apply { gravity = Gravity.CENTER })
    }

    private fun createConnectionErrorView() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(20), dp(20), dp(20), dp(20)); background = gradient(BG, WHITE, 24)
        val card = LinearLayout(this@MainActivity).apply {
            orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL; setPadding(dp(24), dp(26), dp(24), dp(24))
            background = rounded(WHITE, dp(18), STROKE); elevation = dp(3).toFloat()
        }
        val alertMark = TextView(this@MainActivity).apply {
            text = "!"; textSize = 20f; typeface = Typeface.DEFAULT_BOLD; gravity = Gravity.CENTER; setTextColor(ORANGE)
            background = rounded(Color.rgb(255, 241, 221), dp(13))
        }
        card.addView(alertMark, LinearLayout.LayoutParams(dp(44), dp(44)))
        card.addView(label("Portal trenutno nije dostupan", 19f, INK, true).apply { gravity = Gravity.CENTER; setPadding(0, dp(16), 0, dp(8)) })
        connectionMessageView = label("Proverite internet vezu. Vaša aktivacija je sačuvana i ne morate ponovo da unosite kod.", 14f, MUTED, false).apply {
            gravity = Gravity.CENTER; setLineSpacing(dp(3).toFloat(), 1f); setPadding(0, 0, 0, dp(20))
        }
        card.addView(connectionMessageView)
        card.addView(primaryButton("Pokušajte ponovo") { showLoading(); restoreOrActivate() }, matchWrap(dp(48)))
        addView(card, LinearLayout.LayoutParams(minOf(dp(resources.configuration.screenWidthDp - 40), dp(420)), -2).apply { gravity = Gravity.CENTER })
    }

    private fun restoreOrActivate() {
        val saved = DeviceVault.read(this)
        if (saved == null) { showActivation(); return }
        showLoading()
        apiWithRetry("session", JSONObject().put("device_id", saved.first).put("device_token", saved.second)) { result, error ->
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
        if (code.length != 12) { showActivation("Aktivacioni kod mora imati 12 znakova."); return }
        // Every enrollment is a new server-side device record, including after
        // logout/revocation; never reuse an ID protected by the unique DB key.
        val id = UUID.randomUUID().toString()
        val secret = newDeviceSecret()
        try { DeviceVault.write(this, id, secret) } catch (_: Exception) { showActivation("Ne možemo bezbedno sačuvati prijavu na ovom uređaju."); return }
        setBusy(true)
        val body = JSONObject().put("code", code).put("device_id", id).put("device_token", secret).put("platform", "android")
            .put("device_name", "${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}".take(120))
        api("activate", body) { result, error ->
            if (error != null || result?.optBoolean("ok") != true) {
                val status = result?.optInt("http_status") ?: 0
                val activationError = error ?: "Aktivacija nije uspela. Proverite kod i pokušajte ponovo."
                if (status in 400..499) {
                    DeviceVault.clear(this)
                    setBusy(false)
                    showActivation(activationError)
                } else recoverActivation(id, secret, activationError)
                return@api
            }
            setBusy(false)
            locked = false
            establishPortalSession(result) {}
        }
    }

    private fun recoverActivation(deviceId: String, secret: String, activationError: String) {
        apiWithRetry("session", JSONObject().put("device_id", deviceId).put("device_token", secret)) { recovered, recoveryError ->
            setBusy(false)
            if (recovered?.optBoolean("ok") == true && recoveryError == null) {
                locked = false
                establishPortalSession(recovered) {}
            } else if (recovered?.optInt("http_status") == 401) {
                DeviceVault.clear(this)
                showActivation(activationError)
            } else {
                val recoveryDetails = recoveryError ?: recovered?.optString("error")?.takeIf { it.isNotBlank() }
                    ?: "Portal nije potvrdio prijavu ovog uređaja."
                showConnectionError(
                    "$activationError\n\n$recoveryDetails\n\n" +
                        "Podaci uređaja su sačuvani, ali jednokratni kod nije sačuvan. " +
                        "Pokušajte ponovo kada veza bude stabilna."
                )
            }
        }
    }

    private fun refreshDeviceSession(silent: Boolean = false) {
        if (checkingSession) return
        val saved = DeviceVault.read(this) ?: return
        checkingSession = true
        apiWithRetry("session", JSONObject().put("device_id", saved.first).put("device_token", saved.second)) { result, error ->
            checkingSession = false
            if (result == null || (result.optInt("http_status") != 401 && error != null)) {
                // A flaky connection on a background re-check must not tear down a working screen.
                if (!silent) showConnectionError(error)
            } else if (error != null || result.optBoolean("ok") != true) {
                DeviceVault.clear(this)
                clearPortalCookies { showActivation("Pristup ovom uređaju je opozvan. Unesite novi jednokratni kod.") }
            } else establishPortalSession(result) { sessionLoadedAt = System.currentTimeMillis() }
        }
    }

    private fun isAdminRole() = DeviceVault.readRole(this) == "admin"

    private fun establishPortalSession(result: JSONObject, after: () -> Unit) {
        // The server decides whether this device is a reseller or an admin device.
        result.optString("role").takeIf { it == "admin" || it == "reseller" }?.let { DeviceVault.writeRole(this, it) }
        CookieManager.getInstance().flush()
        webView.post {
            showOnly(webView)
            if (reloadPortalAfterSession || webView.url.isNullOrBlank() || webView.url == "about:blank") {
                reloadPortalAfterSession = false
                webView.loadUrl(if (isAdminRole()) "${PORTAL_URL}admin.html" else PORTAL_URL)
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
                val json = try { JSONObject(payload.ifBlank { "{}" }) } catch (_: Exception) {
                    // Non-JSON body (hosting firewall page, PHP warning, ...): show what came back, never the request.
                    val snippet = payload.replace(Regex("<[^>]*>|\\s+"), " ").trim().take(90)
                    runOnUiThread { done(JSONObject().put("http_status", conn.responseCode), "Server je vratio neočekivan odgovor (HTTP ${conn.responseCode}): $snippet") }
                    return@thread
                }
                json.put("http_status", conn.responseCode)
                val message = if (conn.responseCode in 200..299 && json.optBoolean("ok")) null else {
                    val serverMessage = json.optString("error").takeIf { it.isNotBlank() }
                        ?: "Povezivanje nije uspelo (${conn.responseCode})."
                    val reference = json.optString("reference").takeIf { it.isNotBlank() }
                    if (reference == null) serverMessage else "$serverMessage (referenca: $reference)"
                }
                val response = json
                val cookies = conn.headerFields.entries.flatMap { entry ->
                    if (entry.key?.equals("Set-Cookie", ignoreCase = true) == true) entry.value.orEmpty() else emptyList()
                }
                val hasSessionCookie = cookies.any { val name = it.substringBefore(';'); name.startsWith("PWRSRESELLERSESSID=") || name.startsWith("PWRSADMINSESSID=") }
                if (response.optBoolean("ok") && action != "logout" && !hasSessionCookie) {
                    runOnUiThread { done(response, "Sesija nije potvrđena. Pokušajte ponovo.") }
                    return@thread
                }
                fun continueAfterCookies(index: Int) {
                    if (index >= cookies.size) done(response, message)
                    else CookieManager.getInstance().setCookie(PORTAL_ORIGIN, cookies[index]) { accepted ->
                        if (!accepted) done(response, "Ne možemo bezbedno da sačuvamo prijavu na uređaju. Pokušajte ponovo.")
                        else continueAfterCookies(index + 1)
                    }
                }
                // CookieManager.setCookie with a callback needs a thread with a Looper (the UI thread);
                // calling it from this worker thread threw IllegalStateException after the server had already succeeded.
                runOnUiThread {
                    try { continueAfterCookies(0) } catch (e: Exception) {
                        done(response, "Ne možemo bezbedno da sačuvamo prijavu na uređaju (${e.javaClass.simpleName}). Pokušajte ponovo.")
                    }
                }
            } catch (e: Exception) {
                val kind = when (e) {
                    is java.net.SocketTimeoutException -> "isteklo vreme"
                    is java.net.UnknownHostException -> "adresa servera nije pronađena"
                    is javax.net.ssl.SSLException -> "greška bezbedne veze (SSL)"
                    else -> e.javaClass.simpleName
                }
                runOnUiThread { done(null, "Ne možemo da se povežemo sa portalom ($kind). Proverite internet i pokušajte ponovo.") }
            } finally { connection?.disconnect() }
        }
    }

    private fun showActivation(message: String? = null) {
        runOnUiThread {
            showOnly(activationView)
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

    private fun newDeviceSecret(): String {
        val bytes = ByteArray(32).also { SecureRandom().nextBytes(it) }
        return Base64.getUrlEncoder().withoutPadding().encodeToString(bytes)
    }

    private inner class AppBridge {
        @JavascriptInterface fun logout() { runOnUiThread { revokeAndShowActivation() } }

        /** The web app got a 401: re-check this device. Valid -> reload with a fresh session, revoked/deactivated -> activation screen. */
        @JavascriptInterface fun sessionExpired() {
            runOnUiThread {
                val now = System.currentTimeMillis()
                if (now - lastSessionExpiredAt < 5000L) return@runOnUiThread
                lastSessionExpiredAt = now
                reloadPortalAfterSession = true
                refreshDeviceSession()
            }
        }

        @JavascriptInterface fun getAppSettings(): String = JSONObject()
            .put("allowScreenshots", allowScreenshots())
            .put("lockEnabled", lockEnabled() || isAdminRole())
            .put("lockForced", isAdminRole())
            .put("deviceSecure", isDeviceSecure())
            .put("role", DeviceVault.readRole(this@MainActivity))
            .toString()

        @JavascriptInterface fun setAllowScreenshots(value: Boolean) {
            appPrefs.edit().putBoolean("allow_screenshots", value).apply()
            runOnUiThread { applyScreenshotPolicy() }
        }

        /** Returns false when the phone has no screen lock (nothing to unlock with). */
        @JavascriptInterface fun setLockEnabled(value: Boolean): Boolean {
            if (value && !isDeviceSecure()) return false
            appPrefs.edit().putBoolean("lock_enabled", value).apply()
            locked = false
            return true
        }
    }

    // ---- app lock (PIN / pattern / password / fingerprint) and screenshot policy ----
    private fun allowScreenshots() = appPrefs.getBoolean("allow_screenshots", true)
    private fun lockEnabled() = appPrefs.getBoolean("lock_enabled", false)
    private fun isDeviceSecure() = (getSystemService(KEYGUARD_SERVICE) as KeyguardManager).isDeviceSecure
    /** Admin devices are always locked; resellers can opt in from the profile settings. */
    private fun lockRequired() = DeviceVault.read(this) != null && (isAdminRole() || lockEnabled())

    private fun applyScreenshotPolicy(forceSecure: Boolean = false) {
        if (!allowScreenshots() || forceSecure) window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        else window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
    }

    private fun createLockView() = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(24), dp(24), dp(24), dp(24)); background = gradient(BG, WHITE, 24)
        isClickable = true; isFocusable = true
        val card = LinearLayout(this@MainActivity).apply {
            orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL; setPadding(dp(24), dp(28), dp(24), dp(24))
            background = rounded(WHITE, dp(18), STROKE); elevation = dp(3).toFloat()
        }
        card.addView(logoMark(84), LinearLayout.LayoutParams(dp(84), dp(84)))
        card.addView(label("Aplikacija je zaključana", 19f, INK, true).apply { gravity = Gravity.CENTER; setPadding(0, dp(18), 0, dp(6)) })
        lockMessageView = label("Potvrdite identitet otiskom prsta ili PIN-om telefona.", 14f, MUTED, false).apply {
            gravity = Gravity.CENTER; setLineSpacing(dp(3).toFloat(), 1f); setPadding(0, 0, 0, dp(20))
        }
        card.addView(lockMessageView)
        card.addView(primaryButton("Otključaj") { promptUnlock() }, matchWrap(dp(48)))
        card.addView(label("Odjavi uređaj", 14f, BLUE, true).apply {
            gravity = Gravity.CENTER; setPadding(0, dp(18), 0, dp(4)); isClickable = true
            setOnClickListener { locked = false; lockView.visibility = View.GONE; revokeAndShowActivation() }
        })
        addView(card, LinearLayout.LayoutParams(minOf(dp(resources.configuration.screenWidthDp - 48), dp(420)), -2).apply { gravity = Gravity.CENTER })
    }

    private fun showLock() {
        runOnUiThread {
            lockView.visibility = View.VISIBLE
            lockView.bringToFront()
            promptUnlock()
        }
    }

    private fun onUnlocked() {
        locked = false
        authInProgress = false
        lockView.visibility = View.GONE
        applyScreenshotPolicy()
        if (webView.visibility == View.VISIBLE) refreshDeviceSession(silent = true)
    }

    private fun promptUnlock() {
        if (authInProgress) return
        if (!isDeviceSecure()) {
            if (isAdminRole()) {
                lockMessageView.text = "Za admin pristup postavite PIN, šablon ili otisak prsta u podešavanjima telefona, pa se vratite u aplikaciju."
                try { startActivity(Intent(Settings.ACTION_SECURITY_SETTINGS)) } catch (_: Exception) { }
            } else onUnlocked()
            return
        }
        authInProgress = true
        if (Build.VERSION.SDK_INT >= 30) promptUnlockModern() else promptUnlockLegacy()
    }

    @androidx.annotation.RequiresApi(30)
    private fun promptUnlockModern() {
        val authenticators = android.hardware.biometrics.BiometricManager.Authenticators.BIOMETRIC_STRONG or
            android.hardware.biometrics.BiometricManager.Authenticators.DEVICE_CREDENTIAL
        val prompt = android.hardware.biometrics.BiometricPrompt.Builder(this)
            .setTitle("PlayWorld Reseller").setSubtitle("Otključajte aplikaciju")
            .setAllowedAuthenticators(authenticators).build()
        prompt.authenticate(android.os.CancellationSignal(), mainExecutor, object : android.hardware.biometrics.BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: android.hardware.biometrics.BiometricPrompt.AuthenticationResult?) { onUnlocked() }
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence?) {
                authInProgress = false
                lockMessageView.text = "Otključavanje nije uspelo. Pokušajte ponovo."
            }
        })
    }

    @Suppress("DEPRECATION")
    private fun promptUnlockLegacy() {
        val intent = (getSystemService(KEYGUARD_SERVICE) as KeyguardManager).createConfirmDeviceCredentialIntent("PlayWorld Reseller", "Otključajte aplikaciju")
        if (intent == null) { onUnlocked(); return }
        startActivityForResult(intent, REQ_UNLOCK)
    }

    @Suppress("DEPRECATION")
    @Deprecated("Deprecated in Android; used for devices before Android 11")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == REQ_UNLOCK) {
            authInProgress = false
            if (resultCode == RESULT_OK) onUnlocked() else lockMessageView.text = "Otključavanje nije uspelo. Pokušajte ponovo."
        }
    }

    private inner class PortalWebViewClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val uri = request.url
            if (uri.scheme == "https" && uri.host == PORTAL_HOST && (uri.path != "/admin.html" || isAdminRole())) return false
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
            showOnly(errorView)
            connectionMessageView.text = message ?: "Proverite internet vezu. Vaša aktivacija je sačuvana i ne morate ponovo da unosite kod."
            progressBar.visibility = View.GONE
        }
    }

    private fun showLoading() = runOnUiThread { showOnly(loadingView) }

    private fun showOnly(target: View) {
        listOf(webView, activationView, loadingView, errorView).forEach { view ->
            view.animate().cancel()
            if (view === target) animateIn(view) else {
                view.visibility = View.GONE
                view.alpha = 1f
                view.translationY = 0f
            }
        }
    }

    private fun animateIn(view: View) {
        view.animate().cancel()
        view.visibility = View.VISIBLE
        if (android.os.Build.VERSION.SDK_INT >= 26 && !ValueAnimator.areAnimatorsEnabled()) {
            view.alpha = 1f
            view.translationY = 0f
            return
        }
        view.alpha = 0f
        view.translationY = dp(8).toFloat()
        view.animate().alpha(1f).translationY(0f).setDuration(220)
            .setInterpolator(DecelerateInterpolator()).start()
    }

    private fun apiWithRetry(
        action: String,
        body: JSONObject,
        remainingRetries: Int = 2,
        done: (JSONObject?, String?) -> Unit
    ) {
        api(action, body) { result, error ->
            val status = result?.optInt("http_status") ?: 0
            val transientFailure = error != null && (result == null || status in 200..299 || status >= 500 || status == 0)
            if (transientFailure && remainingRetries > 0) {
                val retryNumber = 3 - remainingRetries
                sessionHandler.postDelayed({
                    apiWithRetry(action, body, remainingRetries - 1, done)
                }, if (retryNumber == 1) 650L else 1500L)
            } else done(result, error)
        }
    }
    private fun label(value: String, size: Float, color: Int, bold: Boolean) = TextView(this).apply { text = value; textSize = size; setTextColor(color); if (bold) setTypeface(typeface, Typeface.BOLD) }
    private fun fieldLabel(value: String) = label(value, 13f, INK, true).apply { setPadding(0, dp(16), 0, dp(7)) }
    private fun editField(hintText: String, inputType: Int) = EditText(this).apply {
        hint = hintText; textSize = 15f; setTextColor(INK); setHintTextColor(MUTED); this.inputType = inputType
        imeOptions = EditorInfo.IME_ACTION_NEXT; setSingleLine(true); setPadding(dp(15), 0, dp(15), 0); background = rounded(WHITE, dp(13), STROKE)
    }
    private fun matchWrap(height: Int) = LinearLayout.LayoutParams(-1, height)
    private fun logoMark(sizeDp: Int) = ImageView(this).apply {
        setImageResource(R.drawable.pw_logo)
        scaleType = ImageView.ScaleType.FIT_CENTER
        contentDescription = "PlayWorld Reseller"
        val radius = dp(sizeDp) * 0.22f
        outlineProvider = object : android.view.ViewOutlineProvider() {
            override fun getOutline(view: View, outline: android.graphics.Outline) { outline.setRoundRect(0, 0, view.width, view.height, radius) }
        }
        clipToOutline = true
        elevation = dp(4).toFloat()
    }
    private fun primaryButton(title: String, action: () -> Unit) = Button(this).apply {
        text = title; isAllCaps = false; setTextColor(Color.WHITE); textSize = 15f
        typeface = Typeface.DEFAULT_BOLD; background = gradient(BLUE, PURPLE, 12)
        minHeight = dp(48); minimumHeight = dp(48); minWidth = 0; minimumWidth = 0
        setPadding(dp(16), 0, dp(16), 0); stateListAnimator = null
        setOnClickListener { action() }
    }
    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()
    private fun rounded(color: Int, radius: Int, stroke: Int? = null) = GradientDrawable().apply { setColor(color); cornerRadius = radius.toFloat(); if (stroke != null) setStroke(dp(1), stroke) }
    private fun gradient(start: Int, end: Int, radius: Int) = GradientDrawable(GradientDrawable.Orientation.TL_BR, intArrayOf(start, end)).apply { cornerRadius = dp(radius).toFloat() }

    companion object {
        private const val PORTAL_HOST = "reseller.psigre.rs"
        private const val PORTAL_ORIGIN = "https://reseller.psigre.rs"
        private const val PORTAL_URL = "$PORTAL_ORIGIN/"
        private const val API_URL = "$PORTAL_ORIGIN/api/device_auth.php"
        private const val PREFS = "reseller_device"
        private const val SESSION_REFRESH_MS = 45L * 60L * 1000L
        private const val LOCK_TIMEOUT_MS = 60L * 1000L
        private const val RESUME_CHECK_MS = 20L * 1000L
        private const val REQ_UNLOCK = 4711
        private const val BLUE = 0xFF2563EB.toInt()
        private const val PURPLE = 0xFF4F46E5.toInt()
        private const val BG = 0xFFEEF2F9.toInt()
        private const val WHITE = Color.WHITE
        private const val INK = 0xFF0F172A.toInt()
        private const val MUTED = 0xFF5B6577.toInt()
        private const val STROKE = 0xFFD9E2F0.toInt()
        private const val RED = 0xFFB42318.toInt()
        private const val ORANGE = 0xFFEA580C.toInt()
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
        val saved = activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit()
            .putString("device_id", deviceId)
            .putString(SECRET, Base64.getEncoder().encodeToString(encrypted))
            .putString(IV, Base64.getEncoder().encodeToString(cipher.iv)).commit()
        check(saved) { "Could not persist device credentials" }
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

    fun writeRole(activity: android.app.Activity, role: String) {
        activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit().putString("role", role).commit()
    }

    fun readRole(activity: android.app.Activity): String =
        activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).getString("role", "reseller") ?: "reseller"

    fun clear(activity: android.app.Activity) {
        activity.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit()
            .remove("device_id").remove(SECRET).remove(IV).remove("role").commit()
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
