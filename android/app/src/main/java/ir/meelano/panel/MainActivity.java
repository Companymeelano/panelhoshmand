package ir.meelano.panel;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;

/**
 * پنل هوشمند میلانو — پوشش اندروید (WebView تمام‌صفحه)
 *
 * نسخه وب سامانه را در WebView امن اجرا می‌کند و در صورت خطا/قطعی شبکه، به‌جای
 * صفحهٔ سیاه، یک صفحهٔ راهنمای فارسی با دکمهٔ «تلاش دوباره» نمایش می‌دهد.
 *
 * Meelano Studio Design — Milad Yaghoobi
 */
public class MainActivity extends Activity {

    private WebView webView;
    private ProgressBar progressBar;
    private LinearLayout errorBox;
    private boolean loading = false;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        getWindow().setStatusBarColor(Color.parseColor("#0b1020"));
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);

        webView = new WebView(this);
        progressBar = new ProgressBar(this);
        errorBox = buildErrorBox();

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setTextZoom(100);
        s.setSupportZoom(false);

        webView.setBackgroundColor(Color.parseColor("#0b1020"));
        webView.setWebViewClient(new Client());
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int p) {
                super.onProgressChanged(view, p);
                if (p >= 100) { progressBar.setVisibility(View.GONE); }
            }
        });

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.parseColor("#0b1020"));
        root.addView(webView, lp());
        root.addView(progressBar, lp());
        root.addView(errorBox); // از LayoutParams خودِ errorBox (متمرکز، wrap) استفاده می‌شود
        setContentView(root);

        load();
    }

    private FrameLayout.LayoutParams lp() {
        return new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT);
    }

    private void load() {
        loading = true;
        errorBox.setVisibility(View.GONE);
        progressBar.setVisibility(View.VISIBLE);
        webView.loadUrl(getString(R.string.app_url));
    }

    /** جعبهٔ خطای فارسی (به‌جای صفحهٔ سیاه). */
    private LinearLayout buildErrorBox() {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(64, 48, 64, 48);

        GradientDrawable card = new GradientDrawable();
        card.setColor(Color.parseColor("#111a33"));
        card.setCornerRadius(28f);
        card.setStroke(2, Color.parseColor("#f5b731"));
        box.setBackground(card);
        box.setVisibility(View.GONE);

        TextView icon = new TextView(this);
        icon.setText("\u26A0\uFE0F");           // ⚠️
        icon.setTextSize(44);
        icon.setGravity(Gravity.CENTER);
        box.addView(icon);

        TextView title = new TextView(this);
        title.setText(R.string.err_title);
        title.setTextColor(Color.parseColor("#ffd76a"));
        title.setTextSize(20);
        title.setTypeface(null, Typeface.BOLD);
        title.setGravity(Gravity.CENTER);
        box.addView(title);

        TextView body = new TextView(this);
        body.setText(R.string.err_body);
        body.setTextColor(Color.parseColor("#eef2ff"));
        body.setTextSize(15);
        body.setGravity(Gravity.CENTER);
        body.setPadding(0, 24, 0, 32);
        box.addView(body);

        Button retry = new Button(this);
        retry.setText(R.string.err_retry);
        retry.setTextColor(Color.parseColor("#0b1020"));
        retry.setBackgroundColor(Color.parseColor("#f5b731"));
        retry.setOnClickListener(v -> load());
        box.addView(retry);

        FrameLayout.LayoutParams p = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.WRAP_CONTENT);
        p.gravity = Gravity.CENTER;
        box.setLayoutParams(p);
        return box;
    }

    private void showError() {
        progressBar.setVisibility(View.GONE);
        errorBox.setVisibility(View.VISIBLE);
    }

    private class Client extends WebViewClient {
        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            progressBar.setVisibility(View.GONE);
            loading = false;
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            super.onReceivedError(view, request, error);
            if (request.isForMainFrame()) { showError(); }
        }

        @Override
        public void onReceivedHttpError(WebView view, WebResourceRequest request, android.webkit.WebResourceResponse resp) {
            super.onReceivedHttpError(view, request, resp);
            if (request.isForMainFrame() && resp != null && resp.getStatusCode() >= 400) { showError(); }
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            // هرگز خطای SSL را بی‌صدا رد نکن؛ هشدار بده و متوقف شو
            handler.cancel();
            showError();
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
