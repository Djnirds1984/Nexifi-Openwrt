const http = require('http')
const { execSync } = require('child_process')
function getPortal() {
  try {
    let o = execSync('uci -q get pisowifi.general.portal_url').toString().trim()
    if (o) {
      if (!o.endsWith('/')) o += '/'
      return o
    }
  } catch (e) {}
  return 'http://10.0.0.1/pisowifi/'
}
const portal = getPortal()
const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0]
  const redirect = () => {
    res.statusCode = 302
    res.setHeader('Location', portal)
    res.end()
  }
  if (u === '/generate_204' || u === '/hotspot-detect' || u === '/hotspot-detect.html' || u === '/redirect') {
    redirect()
  } else if (u === '/connecttest.txt' || u === '/ncsi.txt') {
    const body = u === '/connecttest.txt' ? 'Captive Portal' : 'Microsoft NCSI'
    res.statusCode = 200
    res.setHeader('Content-Type', 'text/plain')
    res.setHeader('Content-Length', Buffer.byteLength(body))
    res.end(body)
  } else if (u === '/portal' || u === '/') {
    const body = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pisowifi Portal</title><style>body{font-family:system-ui,Arial,sans-serif;margin:0;padding:24px;background:#0f172a;color:#e2e8f0} .card{max-width:560px;margin:40px auto;background:#111827;border:1px solid #1f2937;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.35)} .card h1{margin:0;padding:20px;border-bottom:1px solid #1f2937;font-size:20px} .card .content{padding:20px;font-size:15px;line-height:1.6} .btn{display:inline-block;background:#2563eb;color:white;border:none;border-radius:8px;padding:12px 18px;margin-top:12px;text-decoration:none} .btn:hover{background:#1d4ed8}</style></head><body><div class="card"><h1>Pisowifi Captive Portal</h1><div class="content"><p>Welcome. Please proceed to payment to get internet access.</p><p>This is the new NodeJS portal page. Your device was redirected here automatically.</p><a class="btn" href="#">Proceed</a></div></div></body></html>`
    res.statusCode = 200
    res.setHeader('Content-Type', 'text/html; charset=utf-8')
    res.setHeader('Content-Length', Buffer.byteLength(body))
    res.end(body)
  } else {
    redirect()
  }
})
server.listen(80, '0.0.0.0')
