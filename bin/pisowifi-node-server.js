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
  if (u === '/generate_204' || u === '/hotspot-detect' || u === '/hotspot-detect.html' || u === '/redirect' || u === '/') {
    redirect()
  } else if (u === '/connecttest.txt' || u === '/ncsi.txt') {
    const body = u === '/connecttest.txt' ? 'Captive Portal' : 'Microsoft NCSI'
    res.statusCode = 200
    res.setHeader('Content-Type', 'text/plain')
    res.setHeader('Content-Length', Buffer.byteLength(body))
    res.end(body)
  } else {
    redirect()
  }
})
server.listen(80, '0.0.0.0')
