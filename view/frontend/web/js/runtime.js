define([], function () {
  'use strict';

  var LOADER = 'https://scripts.selectika.com/selectika-wc.js';
  var PIXEL = 'https://scripts.selectika.com/selectika-pixel.min.js';
  var CONSENT_COOKIE = 'selectika_analytics_consent';
  var EVENTS = ['page_view', 'view_item', 'view_cart', 'add_to_cart', 'begin_checkout', 'purchase'];

  /** The standard pixel owns transport/cookies; this adapter supplies native Magento events. */
  return function createRuntime(win, doc, config, onActivate) {
    var allowed = config.requireConsent === false;
    var activated = false;
    var firstView = false;
    var cartView = false;
    var tracker;
    var seen = Object.create(null);
    var storeKey = 'slk-magento-events:' + config.retailerName + ':' + config.storeCode;
    var variant = null;

    function writeConsent(value) {
      doc.cookie = CONSENT_COOKIE + '=' + (value ? '1' : '0') + '; Path=/; SameSite=Lax; Secure';
    }

    function uuid() {
      if (win.crypto && typeof win.crypto.randomUUID === 'function') {
        return win.crypto.randomUUID();
      }
      return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function appendScript(url, id) {
      var scripts = doc.querySelectorAll('script[src]');
      var filename = url.split('/').pop();
      var exists = Array.prototype.some.call(scripts, function (script) {
        try {
          var source = new URL(script.src, win.location.href);
          var names = filename === 'selectika-wc.js' ? ['selectika-wc.js', 'selectika-wc.full.js'] : [filename];
          return (
            source.origin === 'https://scripts.selectika.com' && names.indexOf(source.pathname.split('/').pop()) !== -1
          );
        } catch (_) {
          return false;
        }
      });
      if (exists) {
        return;
      }
      var element = doc.createElement('script');
      element.id = id;
      element.src = url;
      element.async = true;
      element.defer = true;
      doc.head.appendChild(element);
    }

    function ensureTrack() {
      if (tracker) {
        return tracker;
      }
      var previous = typeof win.selectikaTrack === 'function' ? win.selectikaTrack : null;
      tracker = function () {
        if (!allowed) {
          return;
        }
        var args = Array.prototype.slice.call(arguments);
        if (typeof tracker.process === 'function') {
          tracker.process.apply(tracker, args);
        } else if (previous && typeof previous.process === 'function') {
          previous.apply(win, args);
        } else {
          tracker.queue.push(args);
        }
      };
      tracker.queue = previous && Array.isArray(previous.queue) ? previous.queue : [];
      tracker.t = (previous && previous.t) || Date.now();
      win.selectikaTrack = tracker;
      return tracker;
    }

    function remember(eventId) {
      if (seen[eventId]) {
        return false;
      }
      seen[eventId] = true;
      var keys = Object.keys(seen).slice(-200);
      seen = Object.create(null);
      keys.forEach(function (key) {
        seen[key] = true;
      });
      try {
        win.sessionStorage.setItem(storeKey, JSON.stringify(keys));
      } catch (_) {
        /* Storage may be disabled. Per-document deduplication remains. */
      }
      return true;
    }

    function safeItem(item) {
      if (!item || typeof item.item_id !== 'string' || !item.item_id || item.item_id.length > 512) {
        return null;
      }
      var output = { item_id: item.item_id };
      ['item_sku', 'item_variant', 'item_name'].forEach(function (key) {
        if (typeof item[key] === 'string') {
          output[key] = item[key].slice(0, 512);
        }
      });
      ['price', 'quantity'].forEach(function (key) {
        if (typeof item[key] === 'number' && Number.isFinite(item[key]) && item[key] >= 0) {
          output[key] = item[key];
        }
      });
      return output;
    }

    function emit(name, payload, eventId, occurredAt) {
      if (!allowed || !config.trackingEnabled || !config.publisherId || EVENTS.indexOf(name) === -1) {
        return false;
      }
      eventId = typeof eventId === 'string' && eventId.length > 0 && eventId.length <= 128 ? eventId : uuid();
      if (!remember(eventId)) {
        return false;
      }
      var event = {
        schema_version: 1,
        event_id: eventId,
        occurred_at: typeof occurredAt === 'string' ? occurredAt : new Date().toISOString(),
        event_name: name,
        event_selectika: name,
        selectika_feature: 'commerce',
        selectika_action: name,
        source: 'storefront',
      };
      payload = payload || {};
      ['value', 'tax', 'shipping'].forEach(function (key) {
        if (typeof payload[key] === 'number' && Number.isFinite(payload[key])) {
          event[key] = payload[key];
        }
      });
      if (typeof payload.currency === 'string' && /^[A-Z]{3}$/.test(payload.currency)) {
        event.currency = payload.currency;
      }
      if (name === 'purchase' && typeof payload.transaction_id === 'string') {
        event.transaction_id = payload.transaction_id.slice(0, 128);
      }
      if (Array.isArray(payload.items)) {
        event.items = payload.items.slice(0, 100).map(safeItem).filter(Boolean);
      }
      ensureTrack()('event', name, event);
      return true;
    }

    function activate() {
      if (!allowed || activated) {
        return;
      }
      activated = true;
      try {
        var previousIds = JSON.parse(win.sessionStorage.getItem(storeKey) || '[]');
        if (Array.isArray(previousIds)) {
          previousIds.slice(-200).forEach(function (id) {
            if (typeof id === 'string') {
              seen[id] = true;
            }
          });
        }
      } catch (_) {
        /* No stored history is required to load the storefront. */
      }
      // Install a consent-aware command queue before either existing runtime can initialize it.
      ensureTrack();
      if (config.trackingEnabled && config.publisherId) {
        tracker('init', config.publisherId);
        tracker('param', 'src', 'magento_module');
        appendScript(PIXEL, 'selectika-pixel');
      }
      appendScript(LOADER, 'selectika-magento-loader');
      if (!firstView) {
        firstView = true;
        emit('page_view', {});
        if (config.product && config.product.sku) {
          emit('view_item', {
            items: [
              {
                item_id: config.product.sku,
                item_sku: config.product.sku,
                item_name: config.product.name,
                quantity: 1,
              },
            ],
          });
        }
      }
      if (typeof onActivate === 'function') {
        onActivate();
      }
    }

    function setConsent(value) {
      allowed = value === true;
      writeConsent(allowed);
      if (!allowed && tracker) {
        tracker.queue.length = 0;
      }
      if (allowed) {
        activate();
      }
    }

    function processSection(section) {
      if (!allowed || !section || section.store_code !== config.storeCode) {
        return;
      }
      (Array.isArray(section.events) ? section.events : []).slice(0, 20).forEach(function (event) {
        emit(event.event_name, event, event.event_id, event.occurred_at);
      });
      if (!cartView && section.cart && Array.isArray(section.cart.items) && section.cart.items.length) {
        var name =
          config.pageType === 'checkout_cart_index'
            ? 'view_cart'
            : config.pageType === 'checkout_index_index'
              ? 'begin_checkout'
              : null;
        if (name) {
          cartView = true;
          emit(name, section.cart);
        }
      }
    }

    function setVariant(entityId) {
      var product = config.product;
      var sku = product && product.variants && product.variants[String(entityId)];
      var next = sku ? { entityId: String(entityId), sku: sku } : null;
      if (JSON.stringify(next) === JSON.stringify(variant)) {
        return;
      }
      variant = next;
      [
        ['selectika-selected-variant-id', next && next.entityId],
        ['selectika-selected-variant-sku', next && next.sku],
      ].forEach(function (entry) {
        var input = doc.getElementById(entry[0]);
        if (input) {
          input.value = entry[1] || '';
          input.dispatchEvent(new win.Event('change', { bubbles: true }));
        }
      });
      win.dispatchEvent(
        new win.CustomEvent('selectika:product-context', {
          detail: { parentSku: product && product.sku, variant: variant },
        })
      );
    }

    function start() {
      if (config.requireConsent !== false) {
        allowed = doc.cookie.split(';').some(function (part) {
          return part.trim() === CONSENT_COOKIE + '=1';
        });
      }
      activate();
    }

    return { start: start, setConsent: setConsent, processSection: processSection, setVariant: setVariant };
  };
});
