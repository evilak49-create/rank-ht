const offlinePath = '/api/v1/pwa/offline'
const cachePrefix = 'qvip-public-offline-'
const cacheName = cachePrefix + 'v1'

async function saveOfflinePage() {
  const response = await fetch(offlinePath, { credentials: 'omit', cache: 'no-store', redirect: 'error' })
  if (!response.ok || response.headers.get('X-QVIP-Offline') !== '1'
    || !response.headers.get('Content-Type')?.startsWith('text/html')) {
    throw new Error('Offline page unavailable')
  }
  const cache = await caches.open(cacheName)
  await cache.put(offlinePath, response)
}

self.addEventListener('install', event => event.waitUntil(saveOfflinePage()))

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys()
    await Promise.all(names.filter(name => name.startsWith(cachePrefix) && name !== cacheName)
      .map(name => caches.delete(name)))
    await self.clients.claim()
  })())
})

self.addEventListener('message', event => {
  if (event.data === 'refresh-offline-page') {
    event.waitUntil(saveOfflinePage().catch(() => {}))
  }
})

self.addEventListener('fetch', event => {
  const { request } = event
  if (request.method !== 'GET' || request.mode !== 'navigate') return
  const { origin, pathname } = new URL(request.url)
  if (origin !== self.location.origin || (pathname !== '/' && pathname !== '/index.html')) return

  event.respondWith((async () => {
    try {
      return await fetch(request, { cache: 'no-cache' })
    } catch (error) {
      const cache = await caches.open(cacheName)
      const offline = await cache.match(offlinePath)
      if (offline) return offline
      throw error
    }
  })())
})
