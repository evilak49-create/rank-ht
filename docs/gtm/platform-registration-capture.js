(function () {
  var query = new URLSearchParams(window.location.search);
  var phone = query.get('db_id') || query.get('bd_id');
  var platform = query.get('platform');
  if (!/^[1-9][0-9]9[0-9]{8}$/.test(phone || '') || !platform || platform.length > 120) return;

  var payload = encodeURIComponent(JSON.stringify({ bd_id: phone, platform: platform }));
  document.cookie = 'rank_registration=' + payload + '; Max-Age=1800; Path=/; Secure; SameSite=Lax';
})();
