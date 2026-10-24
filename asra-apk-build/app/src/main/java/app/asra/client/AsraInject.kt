package app.asra.client

object AsraInject {
    const val SCRIPT = """
(function(){
  if (window.__asraBridgeInstalled || !window.AsraNative) return;
  window.__asraBridgeInstalled = true;
  var deviceId = '';
  try { deviceId = AsraNative.getDeviceId() || ''; } catch (e) { deviceId = ''; }

  // Kimliği yalnızca kendi sunucumuzun isteklerine ekle. Request nesnesiyle
  // çağrılan fetch'in özgün başlıklarını koru; dış kökene hiç dokunma.
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function(input, init) {
      try {
        var target = typeof input === 'string' ? input :
          (typeof URL !== 'undefined' && input instanceof URL ? input.href :
           typeof Request !== 'undefined' && input instanceof Request ? input.url : '');
        if (!target) throw new Error('unsupported fetch input');
        var url = new URL(target, location.href);
        if (deviceId && url.origin === location.origin) {
          var originalHeaders = init && init.headers ||
            (typeof Request !== 'undefined' && input instanceof Request ? input.headers : {});
          var headers = new Headers(originalHeaders);
          headers.set('X-Asra-Device-Id', deviceId);
          init = Object.assign({}, init, {headers: headers});
        }
      } catch (e) { /* Hatalı URL: fetch kendi hatasını üretsin. */ }
      return origFetch.call(this, input, init);
    };
  }

  window.asraOpenRoom = function(roomId) {
    var id = Number(roomId);
    if (!Number.isInteger(id) || id < 1) return;
    function go(rooms) {
      var room = (rooms || []).find(function(item) { return Number(item.id) === id; });
      if (room && typeof enterRoom === 'function') enterRoom(room);
    }
    try {
      if (typeof state !== 'undefined' && state.rooms && state.rooms.length) {
        go(state.rooms);
        return;
      }
    } catch (e) {}
    if (typeof loadRooms === 'function') {
      loadRooms(false).then(function() {
        try { go(state.rooms || []); } catch (e) {}
      }).catch(function(){});
    }
  };
})();
"""

    // Geri tuşu web geçmişine değil SPA'nın gerçek ekran durumuna bakar.
    // `click()` mevcut modali/menüyü/ekranı kendi temizleme akışıyla kapatır.
    const val BACK_SCRIPT = """
(function(){
  var dialog = document.getElementById('modal-backdrop');
  if (dialog && !dialog.hidden) {
    var close = document.getElementById('modal-close');
    if (close) { close.click(); return 'handled'; }
  }
  var menu = document.getElementById('account-menu');
  if (menu && !menu.hidden) {
    var toggle = document.getElementById('menu-button');
    if (toggle) { toggle.click(); return 'handled'; }
  }
  var shell = document.getElementById('app-shell');
  var back = document.getElementById('back-button');
  if (shell && !shell.hidden && back && !back.hidden) {
    back.click(); return 'handled';
  }
  var auth = document.getElementById('auth-screen');
  var register = document.getElementById('register-tab');
  if (auth && !auth.hidden && register && register.classList.contains('active')) {
    var login = document.getElementById('login-tab');
    if (login) { login.click(); return 'handled'; }
  }
  return 'native';
})()
"""
}
