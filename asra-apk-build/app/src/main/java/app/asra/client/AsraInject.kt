package app.asra.client

object AsraInject {
    const val SCRIPT = """
(function(){
  if (window.__asraBridgeInstalled) return;
  if (!window.AsraNative) return;
  window.__asraBridgeInstalled = true;
  var deviceId = '';
  try { deviceId = AsraNative.getDeviceId() || ''; } catch (e) { deviceId = ''; }

  function patchHeaders(init) {
    init = init || {};
    var headers;
    try { headers = new Headers(init.headers || {}); } catch (e) { headers = new Headers(); }
    if (deviceId) headers.set('X-Asra-Device-Id', deviceId);
    init.headers = headers;
    init.credentials = init.credentials || 'same-origin';
    return init;
  }

  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function(input, init) {
      return origFetch.call(this, input, patchHeaders(init));
    };
  }

  window.asraOpenRoom = function(roomId) {
    var id = Number(roomId);
    if (!id) return;
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
}
