<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth();
?>
<div class="docs-wrap">
  <div class="page-header">
    <h2>📖 Documentation</h2>
    <p>Monitor Central — Complete Reference Guide</p>
  </div>

  <div class="doc-section">
    <h2>Overview</h2>
    <p>
      <strong>Monitor Central</strong> is a self-hosted PHP 8.0+ security monitoring dashboard that aggregates
      traffic, error, and file integrity data from your PHP sites into a single control panel.
    </p>
    <ul>
      <li>Real-time traffic monitoring with geographic data</li>
      <li>PHP fatal error aggregation</li>
      <li>File integrity monitoring and malware detection</li>
      <li>IP reputation lookups via AbuseIPDB</li>
      <li>Automated threat feed updates</li>
      <li>SSL certificate expiry monitoring</li>
    </ul>
  </div>

  <div class="doc-section">
    <h2>Installation</h2>
    <h3>Requirements</h3>
    <ul>
      <li>PHP 8.0 or higher</li>
      <li>MySQL 5.7+ or MariaDB 10.2+</li>
      <li>PHP extensions: <code>pdo_mysql</code>, <code>curl</code>, <code>openssl</code></li>
    </ul>
    <h3>Steps</h3>
    <ol style="color:var(--text-dim);padding-left:20px;line-height:2;">
      <li>Upload files to your web server</li>
      <li>Run <code>install.php</code> in your browser for guided setup</li>
      <li>Configure API keys in Settings panel</li>
      <li>Add your first site in Site Management</li>
      <li>Install the agent on your monitored sites</li>
    </ol>
    <h3>Quick Manual Install</h3>
    <pre>mysql -u root -p -e "CREATE DATABASE iddigital_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p iddigital_monitor &lt; sql/schema.sql</pre>
    <p>Then edit <code>config.php</code> with your database credentials.</p>
  </div>

  <div class="doc-section">
    <h2>Agent Setup</h2>
    <p>
      The PHP agent (<code>agents/agent.php</code>) collects traffic and error data from your sites and sends
      it to Monitor Central automatically.
    </p>
    <h3>Basic Integration</h3>
    <pre>&lt;?php
define('MC_API_URL', 'https://your-monitor.com/ingest.php');
define('MC_API_KEY', 'your_site_api_key_here');
require_once '/path/to/agent.php';</pre>
    <p>Place this at the very top of your site's entry point (e.g., <code>index.php</code> or <code>wp-config.php</code>).</p>

    <h3>WordPress</h3>
    <p>Upload <code>integrations/wordpress/monitor-central.php</code> to your plugins directory and activate it.
    Configure the API URL and key in <strong>Settings → Monitor Central</strong>.</p>

    <h3>PrestaShop</h3>
    <p>Upload the <code>integrations/prestashop/</code> folder to <code>modules/MonitorCentral/</code> and
    install from the back office modules page.</p>
  </div>

  <div class="doc-section">
    <h2>API Reference</h2>
    <h3>Ingest Endpoint</h3>
    <p><code>POST /ingest.php</code> — Receives data from agents</p>
    <pre>{
  "api_key": "your_site_api_key",
  "type": "traffic",
  "data": [{
    "ip": "1.2.3.4",
    "url": "/page",
    "method": "GET",
    "status_code": 200,
    "user_agent": "Mozilla/5.0...",
    "response_time": 0.12
  }]
}</pre>
    <p>Supported types: <code>traffic</code>, <code>errors</code>, <code>file_changes</code>, <code>heartbeat</code>, <code>get_watch_list</code></p>

    <h3>Traffic Type</h3>
    <pre>{
  "type": "traffic",
  "data": [{ "ip", "url", "method", "status_code", "user_agent", "referer", "response_time" }]
}</pre>

    <h3>Errors Type</h3>
    <pre>{
  "type": "errors",
  "data": [{ "type": "E_ERROR", "message", "file", "line", "url", "ip" }]
}</pre>

    <h3>Heartbeat Type</h3>
    <pre>{
  "type": "heartbeat",
  "data": { "http_status": 200 }
}</pre>
  </div>

  <div class="doc-section">
    <h2>WordPress Plugin</h2>
    <p>The WordPress plugin hooks into WordPress to capture all HTTP traffic automatically.</p>
    <h3>Features</h3>
    <ul>
      <li>Auto-captures all page requests via WordPress <code>init</code> hook</li>
      <li>Sends PHP fatal errors via <code>shutdown</code> hook</li>
      <li>Settings page at Settings → Monitor Central</li>
      <li>Uses <code>wp_remote_post()</code> — no extra curl required</li>
    </ul>
    <h3>Configuration</h3>
    <pre>// In WordPress admin: Settings → Monitor Central
API URL: https://your-monitor.com/ingest.php
API Key: (copy from Monitor Central → Site Management)</pre>
  </div>

  <div class="doc-section">
    <h2>PrestaShop Module</h2>
    <p>The PrestaShop module integrates with PrestaShop's hook system.</p>
    <h3>Installation</h3>
    <ol style="color:var(--text-dim);padding-left:20px;line-height:2;">
      <li>Upload <code>MonitorCentral/</code> to <code>modules/</code></li>
      <li>Go to Modules → Module Manager</li>
      <li>Search for "Monitor Central" and click Install</li>
      <li>Configure API URL and key in module settings</li>
    </ol>
  </div>

  <div class="doc-section">
    <h2>Pixel Tracker</h2>
    <p>
      For non-PHP sites (static HTML, email campaigns), use the pixel tracker
      <code>agents/pixel.php</code>:
    </p>
    <pre>&lt;img src="https://your-monitor.com/agents/pixel.php?k=YOUR_API_KEY&amp;u=https%3A%2F%2Fexample.com%2Fpage"
     width="1" height="1" alt=""&gt;</pre>
    <p>Parameters: <code>k</code> = API key, <code>u</code> = page URL, <code>r</code> = referrer</p>
  </div>
</div>
