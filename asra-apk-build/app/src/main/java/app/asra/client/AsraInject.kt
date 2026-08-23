package app.asra.client

object AsraInject {
    const val SCRIPT = """
(function(){
  if (window.__asraBridgeInstalled) return;
  if (!window.AsraNative) return;
  window.__asraBridgeInstalled = true;
  var deviceId = '';
  try { deviceId = AsraNative.getDeviceId() || ''; } catch (e) { deviceId = ''; }

  function hrefOf(input) {
    if (!input) return '';
    if (typeof input === 'string') return input;
    try {
      if (typeof URL !== 'undefined' && input instanceof URL) return input.href || '';
    } catch (e) {}
    if (input.href) return String(input.href);
    if (input.url) return String(input.url);
    return String(input);
  }

  function currentContext() {
    var route = '';
    var roomId = 0;
    try {
      if (typeof state !== 'undefined' && state) {
        route = state.route || '';
        roomId = (state.currentRoom && state.currentRoom.id) || 0;
      }
    } catch (e) {}
    return { route: route, currentRoomId: roomId, visible: document.visibilityState };
  }

  function emitRooms(rooms) {
    if (!rooms) return;
    var ctx = currentContext();
    try {
      AsraNative.notifyRooms(JSON.stringify({
        rooms: rooms.map(function(room) {
          return {
            id: room.id,
            name: room.name,
            unread: Number(room.unread_count || room.unread || 0)
          };
        }),
        route: ctx.route,
        currentRoomId: ctx.currentRoomId,
        visible: ctx.visible
      }));
    } catch (e) {}
  }

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
      return origFetch.call(this, input, patchHeaders(init)).then(function(res) {
        try {
          var href = hrefOf(input);
          if (href.indexOf('action=get_rooms') !== -1 || href.indexOf('get_rooms') !== -1) {
            res.clone().json().then(function(data) {
              if (data && data.rooms) emitRooms(data.rooms);
            }).catch(function(){});
          }
        } catch (e) {}
        return res;
      });
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

  try { AsraNative.setWatching(true); } catch (e) {}
})();
"""
}
