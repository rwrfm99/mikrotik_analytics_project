/**
 * siteLabel.js
 * Converts a raw PTR hostname into a human-readable brand/site name.
 *
 * Strategy:
 *  1. Extract the registrable domain (eTLD+1) from the PTR.
 *  2. Look it up in the KNOWN_DOMAINS map for a friendly brand name.
 *  3. If not found, return the registrable domain itself (e.g. "example.com").
 *  4. Fall back to dns_status labels when there is no PTR at all.
 */

// ---------------------------------------------------------------------------
// Known two-part TLDs that must be treated as a single suffix.
// Only list ones that actually appear in PTR records you are likely to see.
// ---------------------------------------------------------------------------
const MULTI_TLDS = new Set([
  'com.ar','com.br','com.co','com.mx','com.pe','com.ve',
  'co.uk','co.nz','co.za','co.jp','co.kr',
  'net.br','org.br','gov.br','edu.br',
  'com.au','net.au',
])

/**
 * Returns the registrable domain (eTLD+1) for a given hostname.
 * Examples:
 *   instagram-p3-shv-02-mia3.fbcdn.net  →  fbcdn.net
 *   cdn.whatsapp.net                     →  whatsapp.net
 *   s3.amazonaws.com                     →  amazonaws.com
 *   foo.bar.com.br                       →  bar.com.br
 */
export function registrableDomain(hostname) {
  if (!hostname) return ''
  const parts = hostname.toLowerCase().replace(/\.$/, '').split('.')
  if (parts.length < 2) return hostname.toLowerCase()

  // Check for known two-part TLD (e.g. com.br)
  const twoPartSuffix = parts.slice(-2).join('.')
  if (MULTI_TLDS.has(twoPartSuffix) && parts.length >= 3) {
    return parts.slice(-3).join('.')
  }
  return parts.slice(-2).join('.')
}

// ---------------------------------------------------------------------------
// Map from registrable domain → friendly brand name.
// Add entries here as you discover new domains in your network.
// ---------------------------------------------------------------------------
const KNOWN_DOMAINS = {
  // Meta / Facebook / Instagram / WhatsApp / Threads
  'facebook.com':      'Facebook',
  'fb.com':            'Facebook',
  'fbcdn.net':         'Instagram / Facebook',
  'fb.me':             'Facebook',
  'instagram.com':     'Instagram',
  'cdninstagram.com':  'Instagram',
  'whatsapp.com':      'WhatsApp',
  'whatsapp.net':      'WhatsApp',
  'wa.me':             'WhatsApp',
  'threads.net':       'Threads',

  // Google
  'google.com':        'Google',
  'googleapis.com':    'Google APIs',
  'googleusercontent.com': 'Google',
  'googlevideo.com':   'YouTube',
  'youtube.com':       'YouTube',
  'ytimg.com':         'YouTube',
  'googlesyndication.com': 'Google Ads',
  'doubleclick.net':   'Google Ads',
  'googletagmanager.com': 'Google Tag Manager',
  'gstatic.com':       'Google',
  'gmail.com':         'Gmail',
  'google-analytics.com': 'Google Analytics',
  'googleadservices.com': 'Google Ads',
  'ggpht.com':         'Google',
  'googleoptimize.com':'Google Optimize',
  'google.co':         'Google',
  'android.com':       'Android / Google',

  // Apple
  'apple.com':         'Apple',
  'icloud.com':        'iCloud',
  'mzstatic.com':      'App Store / Apple',
  'aaplimg.com':       'Apple',
  'appleanalytics.com':'Apple Analytics',

  // Microsoft
  'microsoft.com':     'Microsoft',
  'windows.com':       'Windows Update',
  'windowsupdate.com': 'Windows Update',
  'office.com':        'Microsoft Office',
  'outlook.com':       'Outlook',
  'hotmail.com':       'Outlook',
  'live.com':          'Microsoft Live',
  'azure.com':         'Azure',
  'azureedge.net':     'Azure CDN',
  'msecnd.net':        'Azure CDN',
  'msftconnecttest.com': 'Windows (prueba de conectividad)',
  'skype.com':         'Skype',
  'teams.microsoft.com': 'Microsoft Teams',
  'bing.com':          'Bing',
  'trafficmanager.net':'Microsoft Traffic Manager',
  'sharepoint.com':    'SharePoint',
  'onedrive.com':      'OneDrive',
  'xbox.com':          'Xbox',

  // Amazon / AWS
  'amazon.com':        'Amazon',
  'amazonaws.com':     'Amazon AWS',
  'cloudfront.net':    'Amazon CloudFront',
  'amazonvideo.com':   'Amazon Prime Video',
  'primevideo.com':    'Amazon Prime Video',
  'amazonmusic.com':   'Amazon Music',
  'a2z.com':           'Amazon',

  // Netflix
  'netflix.com':       'Netflix',
  'nflxvideo.net':     'Netflix',
  'nflxext.com':       'Netflix',
  'nflximg.net':       'Netflix',

  // TikTok / ByteDance
  'tiktok.com':        'TikTok',
  'tiktokcdn.com':     'TikTok CDN',
  'tiktokv.com':       'TikTok Video',
  'bytedance.com':     'ByteDance / TikTok',
  'ibyteimg.com':      'TikTok',

  // Twitter / X
  'twitter.com':       'Twitter / X',
  'x.com':             'Twitter / X',
  'twimg.com':         'Twitter / X',
  't.co':              'Twitter / X',

  // Telegram
  'telegram.org':      'Telegram',
  't.me':              'Telegram',

  // Spotify
  'spotify.com':       'Spotify',
  'scdn.co':           'Spotify CDN',
  'spotifycdn.com':    'Spotify CDN',
  'pscdn.co':          'Spotify',

  // Cloudflare
  'cloudflare.com':    'Cloudflare',
  'cloudflare-dns.com':'Cloudflare DNS',
  '1dot1dot1dot1.cloudflare-dns.com': 'Cloudflare DNS',

  // Akamai
  'akamai.net':        'Akamai CDN',
  'akamaiedge.net':    'Akamai CDN',
  'akamaized.net':     'Akamai CDN',
  'edgekey.net':       'Akamai CDN',
  'akamaitechnologies.com': 'Akamai',

  // Fastly
  'fastly.net':        'Fastly CDN',
  'fastlylb.net':      'Fastly CDN',

  // Snap / Snapchat
  'snap.com':          'Snapchat',
  'snapchat.com':      'Snapchat',
  'sc-cdn.net':        'Snapchat CDN',

  // LinkedIn
  'linkedin.com':      'LinkedIn',
  'licdn.com':         'LinkedIn CDN',

  // Pinterest
  'pinterest.com':     'Pinterest',
  'pinimg.com':        'Pinterest CDN',

  // Zoom
  'zoom.us':           'Zoom',
  'zoomgov.com':       'Zoom',

  // Dropbox
  'dropbox.com':       'Dropbox',
  'dropboxstatic.com': 'Dropbox',

  // GitHub / GitLab
  'github.com':        'GitHub',
  'githubusercontent.com': 'GitHub',
  'gitlab.com':        'GitLab',

  // Twitch
  'twitch.tv':         'Twitch',
  'jtvnw.net':         'Twitch CDN',
  'twitchapps.com':    'Twitch',
  'twitchdvr.com':     'Twitch CDN',

  // Discord
  'discord.com':       'Discord',
  'discordapp.com':    'Discord',
  'discordapp.net':    'Discord CDN',

  // Adobe
  'adobe.com':         'Adobe',
  'adobedtm.com':      'Adobe Analytics',
  'omtrdc.net':        'Adobe Analytics',

  // Shopify
  'shopify.com':       'Shopify',
  'myshopify.com':     'Shopify',

  // Cloudinary
  'cloudinary.com':    'Cloudinary',

  // CDNs genéricos
  'cdn77.com':         'CDN77',
  'cdnjs.cloudflare.com': 'cdnjs',
  'jsdelivr.net':      'jsDelivr CDN',
  'bootstrapcdn.com':  'Bootstrap CDN',

  // DNS / NTP
  '1.1.1.1':           'Cloudflare DNS',
  '8.8.8.8':           'Google DNS',
  '8.8.4.4':           'Google DNS',
  'time.windows.com':  'NTP Windows',
  'pool.ntp.org':      'NTP Pool',

  // ── Agrega aquí tus dominios locales / regionales ──
  // 'midominio.com': 'Mi Servicio',
}

// ---------------------------------------------------------------------------

const STATUS_LABELS = {
  pending:   'Pendiente',
  not_found: 'Sin registro PTR',
  error:     'No disponible',
  private:   'IP privada',
}

/**
 * Returns a human-readable site label for a row with { ptr, dns_status }.
 * If a known brand is found, shows "Brand (domain)".
 * Otherwise shows the registrable domain, the full PTR, or a status label.
 *
 * @param {{ ptr?: string|null, dns_status?: string }} row
 * @returns {string}
 */
export function siteLabel(row) {
  if (!row?.ptr) {
    return STATUS_LABELS[row?.dns_status] || '—'
  }
  const domain = registrableDomain(row.ptr)
  const brand  = KNOWN_DOMAINS[domain]
  if (brand) return `${brand} (${domain})`
  // Unknown domain: show registrable domain only (cleaner than the full subdomain hostname)
  return domain || row.ptr
}
