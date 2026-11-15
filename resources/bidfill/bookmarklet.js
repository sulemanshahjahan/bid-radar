// BidRadar Fill: fills the Freelancer bid form from the #bidradar=... fragment that BidRadar's
// "Fill bid" link adds. It never presses Place Bid. Comment lines are stripped from the bookmark.
var m = location.hash.match(/bidradar=([A-Za-z0-9_-]+)/);
if (!m) { alert('BidRadar Fill: open this project with the "Fill bid" button in BidRadar first.'); return; }
var b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
while (b64.length % 4) b64 += '=';
var p;
try { p = JSON.parse(decodeURIComponent(escape(atob(b64)))); } catch (e) { alert('BidRadar Fill: the bid data could not be read.'); return; }
// EU (with France's overseas regions), UK, US, Canada, Australia, New Zealand: the owner's sponsor countries.
var SPONSOR = /(United States|United Kingdom|Canada|Australia|New Zealand|Austria|Belgium|Bulgaria|Croatia|Cyprus|Czech|Denmark|Estonia|Finland|France|Germany|Greece|Hungary|Ireland|Italy|Latvia|Lithuania|Luxembourg|Malta|Netherlands|Poland|Portugal|Romania|Slovakia|Slovenia|Spain|Sweden|Reunion|Réunion|Guadeloupe|Martinique|French Guiana|Mayotte)/;
var MAX_SPONSOR_USD = 6;
function set(el, v) {
  if (!el) return;
  var proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, String(v));
  ['input', 'change', 'blur'].forEach(function (e) { el.dispatchEvent(new Event(e, { bubbles: true })); });
}
function banner(text, ok) {
  var d = document.getElementById('bidradar-fill') || document.createElement('div');
  d.id = 'bidradar-fill';
  d.style.cssText = 'position:fixed;top:12px;right:12px;z-index:2147483647;max-width:380px;padding:12px 14px;border-radius:10px;font:14px/1.4 sans-serif;color:#fff;box-shadow:0 4px 18px rgba(0,0,0,.3);background:' + (ok ? '#0f766e' : '#b91c1c');
  d.textContent = text;
  document.body.appendChild(d);
}
var tries = 0;
(function fill() {
  var amount = document.querySelector('input[placeholder="Enter bid amount"]');
  if (!amount) {
    if (++tries > 40) { banner('BidRadar Fill: no bid form on this page (already bid, or not signed in?).', false); return; }
    return setTimeout(fill, 250);
  }
  set(amount, p.a);
  var period = document.querySelector('input[placeholder*="hours" i]') || document.querySelector('input[placeholder*="days" i]');
  var isHours = period && /hours/i.test(period.placeholder);
  set(period, isHours ? (p.hrs || 10) : (p.d || 7));
  set(document.querySelector('textarea'), p.t);
  var ms = Array.prototype.filter.call(document.querySelectorAll('input'), function (e) {
    return e !== amount && /milestone|amount/i.test((e.placeholder || '') + (e.getAttribute('aria-label') || ''));
  });
  if (ms.length) set(ms[ms.length - 1], p.a);
  var text = document.body.innerText;
  var ci = text.indexOf('About the Client');
  var client = ci >= 0 ? text.slice(ci, ci + 400).replace(/\s+/g, ' ') : '';
  var eligible = SPONSOR.test(client.slice(0, 120)) && /Payment verified/.test(client);
  var notes = [];
  Array.prototype.forEach.call(document.querySelectorAll('input[type=checkbox]'), function (c) {
    var n = c, label = '';
    for (var k = 0; k < 6 && n; k++) { n = n.parentElement; label = (n && n.innerText) || ''; if (/highlight|sponsor|seal|urgent|nda/i.test(label)) break; }
    label = label.replace(/\s+/g, ' ');
    var free = /free/i.test(label);
    if (/sponsor/i.test(label) && !/highlight/i.test(label)) {
      var price = parseFloat((label.match(/\$\s?([\d.]+)/) || [])[1] || '99');
      var want = eligible && price <= MAX_SPONSOR_USD;
      if (c.checked !== want) c.click();
      notes.push(want ? 'Sponsored ticked ($' + price + ')' : 'not sponsored' + (eligible ? ' (over $' + MAX_SPONSOR_USD + ')' : ' (client not eligible)'));
    } else if (/highlight/i.test(label)) {
      if (free && !c.checked) c.click();
      if (!free && c.checked) c.click();
      notes.push(free ? 'highlight (free) ticked' : 'highlight not free: left off');
    } else if (c.checked && !free) {
      c.click();
    }
  });
  var btn = Array.prototype.find.call(document.querySelectorAll('button'), function (x) { return /^Place Bid/i.test(x.innerText.trim()); });
  if (btn) { btn.scrollIntoView({ block: 'center' }); btn.style.outline = '4px solid #f59e0b'; }
  banner('BidRadar filled: ' + p.c + ' ' + p.a + (p.h ? '/hr' : '') + ', ' + (isHours ? (p.hrs || 10) + ' hours' : (p.d || 7) + ' days') + '. ' + notes.join('; ') + '. Check it, then press ' + (btn ? '"' + btn.innerText.trim() + '"' : 'Place Bid') + '.', true);
})();
