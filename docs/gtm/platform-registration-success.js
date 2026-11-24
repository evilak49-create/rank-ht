(function () {
  var query = new URLSearchParams(window.location.search);
  var phone = query.get('bd_id');
  var platform = query.get('platform');
  if (!phone || !platform) {
    var cookie = document.cookie.match(/(?:^|;\s*)rank_registration=([^;]*)/);
    if (!cookie) return;
    try {
      var saved = JSON.parse(decodeURIComponent(cookie[1]));
      if ((phone && phone !== saved.bd_id) || (platform && platform !== saved.platform)) return;
      phone = saved.bd_id;
      platform = saved.platform;
    } catch (error) {
      return;
    }
  }
  if (!/^[1-9][0-9]9[0-9]{8}$/.test(phone || '') || typeof platform !== 'string' ||
      !platform || platform.length > 120) return;

  var callback = new URL('https://rank.09vip.cc/api/v1/platform-autobind');
  callback.searchParams.set('bd_id', phone);
  callback.searchParams.set('platform', platform);
  fetch(callback.href, {
    method: 'GET',
    mode: 'no-cors',
    credentials: 'omit',
    cache: 'no-store',
    keepalive: true,
    referrerPolicy: 'no-referrer'
  }).catch(function () {});
})();
