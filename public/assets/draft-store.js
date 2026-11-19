'use strict';
(function(root){
var PREFIX = 'kiption:writer:v1:';
var NAMES = ['title', 'content', 'notes_before', 'notes_after', 'publish_at'];
var LIMIT = 2000000;
function key(owner, chapter) { return PREFIX + encodeURIComponent(owner) + ':' + encodeURIComponent(chapter); }
function fields(value, names) {
  var result = Object.create(null);
  (names || NAMES).forEach(function (name) { result[name] = typeof value[name] === 'string' ? value[name] : ''; });
  return result;
}
function validFields(value, names) {
  names = names || NAMES;
  return value && typeof value === 'object' && !Array.isArray(value) &&
    Object.keys(value).length === names.length && names.every(function (name) { return typeof value[name] === 'string'; });
}
function remove(storage, k) { try { storage.removeItem(k); return true; } catch (_) { return false; } }
function write(storage, k, base, value, now, names) {
  try {
    if (k.indexOf(PREFIX) !== 0 || !/^(new|[a-f0-9]{64})$/.test(base)) return false;
    var raw = JSON.stringify({ v: 1, key: k, base: base, at: now, fields: fields(value, names) });
    if (raw.length > LIMIT) { remove(storage, k); return false; }
    storage.setItem(k, raw);
    return true;
  } catch (_) { return false; }
}
function read(storage, k, now, names) {
  try {
    var raw = storage.getItem(k);
    if (raw === null) return null;
    if (raw.length > LIMIT) { remove(storage, k); return null; }
    var value = JSON.parse(raw);
    if (!value || value.v !== 1 || value.key !== k || (!Number.isFinite(value.at) || value.at <= 0) ||
        value.at > now + 300000 || now - value.at > 86400000 ||
        !/^(new|[a-f0-9]{64})$/.test(value.base) || !validFields(value.fields, names)) {
      remove(storage, k); return null;
    }
    return value;
  } catch (_) { remove(storage, k); return null; }
}
function enabled(storage, owner) {
  try { return storage.getItem(key(owner, 'enabled')) === '1'; } catch (_) { return false; }
}
function setEnabled(storage, owner, value) {
  if (!value) return clearOwner(storage, owner);
  try { storage.setItem(key(owner, 'enabled'), '1'); return true; } catch (_) { return false; }
}
function clearOwner(storage, owner) {
  try {
    var prefix = PREFIX + encodeURIComponent(owner) + ':', cleared = true;
    for (var i = storage.length - 1; i >= 0; i--) {
      var k = storage.key(i);
      if (k && k.indexOf(prefix) === 0 && !remove(storage, k)) cleared = false;
    }
  } catch (_) { return false; }
  return cleared;
}
var api={key:key,fields:fields,read:read,write:write,remove:remove,enabled:enabled,setEnabled:setEnabled,clearOwner:clearOwner};
if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root.Kip)root.Kip.draftStore=api;
})(typeof window!=='undefined'?window:globalThis);
