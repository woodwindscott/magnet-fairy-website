// The Magnet Fairy — shop
//
// Flow: pick a size + pack -> one photo slot per magnet -> customer picks a
// photo -> the ORIGINAL file uploads straight from the browser to Scott's
// Dropbox (via a temporary upload link from api/get-upload-link.php) while
// the customer crops it -> on "Use this crop" a print-ready JPEG of the crop
// (full resolution, at least 300 dpi) uploads too -> pack goes in the cart (localStorage)
// -> checkout posts the cart to api/create-checkout.php, which checks prices
// on the server and redirects to Stripe Checkout.
//
// Demo mode (?demo=1, or any host other than themagnetfairy.com/localhost)
// fakes the uploads and checkout so the flow can be tried anywhere.

(function () {
  "use strict";

  var API = "../api/";
  var STORE_KEY = "mf_cart_v1";
  var LAST_ORDER_KEY = "mf_last_order_v1";
  var host = location.hostname;
  var params = new URLSearchParams(location.search);
  var DEMO =
    params.has("demo") ||
    !(/(^|\.)themagnetfairy\.com$/.test(host) || host === "localhost" || host === "127.0.0.1");

  var catalog = null;
  var cart = loadCart();
  var draft = null; // pack being built: {size, pack, photos: []}
  var slotIndex = -1; // slot waiting for a file
  var cropTarget = null; // photo being cropped
  var cropper = null;
  var cropOrientation = null;

  var $ = function (id) { return document.getElementById(id); };

  // ---------- storage (never trusted to work) ----------
  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); return true; } catch (e) { return false; }
  }
  function storageRemove(key) {
    try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
  }

  function newId(len) {
    var chars = "abcdefghijkmnpqrstuvwxyz23456789";
    var out = "";
    var bytes = new Uint8Array(len || 20);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    for (var i = 0; i < bytes.length; i++) out += chars[bytes[i] % chars.length];
    return out;
  }

  function loadCart() {
    try {
      var c = JSON.parse(storageGet(STORE_KEY));
      if (c && typeof c.id === "string" && Array.isArray(c.items)) return c;
    } catch (e) { /* ignore */ }
    return { id: newId(20), items: [] };
  }

  function saveCart() {
    if (storageSet(STORE_KEY, JSON.stringify(cart))) return;
    // Probably out of space: drop thumbnails and try again.
    var slim = JSON.parse(JSON.stringify(cart));
    slim.items.forEach(function (it) { it.photos.forEach(function (p) { p.thumb = ""; }); });
    storageSet(STORE_KEY, JSON.stringify(slim));
  }

  // ---------- helpers ----------
  function money(cents) {
    return "$" + (cents / 100).toFixed(2);
  }
  function shortMoney(cents) {
    return cents % 100 === 0 ? "$" + cents / 100 : money(cents);
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function sizeInfo(size) { return catalog.sizes[size]; }
  function packPrice(size, pack) { return sizeInfo(size).packs[String(pack)]; }
  function packName(size, pack) {
    return sizeInfo(size).name + (pack > 1 ? " — " + pack + "-pack" : "");
  }
  function paperMm(size, orientation) {
    var p = sizeInfo(size).paper_mm;
    var w = Math.min(p[0], p[1]), h = Math.max(p[0], p[1]);
    return orientation === "landscape" ? [h, w] : [w, h];
  }
  function faceMm(size, orientation) {
    var f = sizeInfo(size).face_mm;
    var w = Math.min(f[0], f[1]), h = Math.max(f[0], f[1]);
    return orientation === "landscape" ? [h, w] : [w, h];
  }
  function printPx(size, orientation) {
    var mm = paperMm(size, orientation);
    var dpi = catalog.print_dpi || 300;
    return [Math.round((mm[0] / 25.4) * dpi), Math.round((mm[1] / 25.4) * dpi)];
  }

  // ---------- catalog ----------
  function loadCatalog() {
    return fetch("products.json", { cache: "no-cache" })
      .then(function (r) {
        if (!r.ok) throw new Error("products.json " + r.status);
        return r.json();
      });
  }

  function renderProducts() {
    var box = $("products");
    box.innerHTML = "";
    Object.keys(catalog.sizes).forEach(function (key) {
      var s = catalog.sizes[key];
      var card = el("div", "product");
      var visual = el("div", "product-visual");
      visual.appendChild(el("div", "product-shape " + key));
      card.appendChild(visual);
      card.appendChild(el("h3", null, s.name));
      card.appendChild(el("p", "product-dims", s.dimensions));
      var packs = el("div", "packs");
      Object.keys(s.packs).forEach(function (n) {
        var count = parseInt(n, 10);
        var btn = el("button", "pack");
        btn.type = "button";
        btn.setAttribute("aria-label", "Order " + count + " " + s.short.toLowerCase() + " magnet" + (count > 1 ? "s" : "") + " for " + money(s.packs[n]));
        btn.appendChild(el("span", "pack-count", count === 1 ? "1 magnet" : count + " magnets"));
        btn.appendChild(el("span", "pack-price", shortMoney(s.packs[n])));
        btn.appendChild(el("span", "pack-each", count === 1 ? "single" : money(Math.round(s.packs[n] / count)) + " each"));
        btn.addEventListener("click", function () { openBuilder(key, count); });
        packs.appendChild(btn);
      });
      card.appendChild(packs);
      box.appendChild(card);
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-shipping]"), function (n) {
      n.textContent = shortMoney(catalog.shipping_cents);
    });
  }

  // ---------- builder ----------
  function openBuilder(size, pack) {
    if (!draft || draft.size !== size || draft.pack !== pack) {
      draft = { size: size, pack: pack, photos: new Array(pack).fill(null) };
    }
    $("builder-title").textContent = packName(size, pack);
    $("builder-sub").textContent =
      sizeInfo(size).dimensions + " · " + (pack === 1 ? "Add 1 photo" : "Add " + pack + " photos, one for each magnet");
    $("builder-price").textContent = money(packPrice(size, pack));
    renderSlots();
    showSheet("builder");
  }

  function photoReady(p) {
    if (!p || p.origStatus !== "done") return false;
    if (p.scottCrops) return true; // Scott crops it himself; no print file
    return !!p.crop && p.printStatus === "done";
  }

  function renderSlots() {
    var box = $("slots");
    box.innerHTML = "";
    draft.photos.forEach(function (p, i) {
      var slot = el("button", "slot" + (draft.size === "square" ? " square-slot" : ""));
      slot.type = "button";
      if (!p) {
        slot.appendChild(el("span", "slot-plus", "+"));
        slot.appendChild(el("span", "slot-label", "Magnet " + (i + 1)));
        slot.setAttribute("aria-label", "Choose photo for magnet " + (i + 1));
      } else {
        slot.classList.add("filled");
        slot.setAttribute("aria-label", "Magnet " + (i + 1) + ": edit crop");
        if (p.thumb) {
          var img = el("img");
          img.src = p.thumb;
          img.alt = "";
          slot.appendChild(img);
        }
        var status = el("span", "slot-status");
        if (p.origStatus === "error" || (!p.scottCrops && p.printStatus === "error")) {
          status.classList.add("err");
          status.textContent = "Upload failed. Tap to retry";
        } else if (photoReady(p)) {
          status.classList.add("ok");
          status.textContent = p.scottCrops ? "I'll crop this one" : "Ready · tap to re-crop";
        } else if (!p.crop && !p.scottCrops) {
          status.textContent = "Needs cropping";
        } else {
          status.textContent = "Uploading " + Math.round((p.progress || 0) * 100) + "%";
          var bar = el("span", "slot-bar");
          bar.style.width = Math.round((p.progress || 0) * 100) + "%";
          slot.appendChild(bar);
        }
        slot.appendChild(status);
      }
      slot.addEventListener("click", function () { onSlot(i); });
      box.appendChild(slot);
    });
    $("builder-add").disabled = !draft.photos.every(photoReady);
  }

  function onSlot(i) {
    var p = draft.photos[i];
    if (p && (p.origStatus === "error" || (!p.scottCrops && p.printStatus === "error"))) {
      if (p.file) { retryUploads(p); return; }
    }
    if (p && p.objectUrl) {
      openCropper(p);
      return;
    }
    slotIndex = i;
    var input = $("file-input");
    input.value = "";
    input.click();
  }

  function onFileChosen(file) {
    if (!file || slotIndex < 0) return;
    var okType = /^image\/(jpeg|png)$/.test(file.type) || (!file.type && /\.(jpe?g|png)$/i.test(file.name));
    if (!okType) {
      window.setTimeout(function () {
        showToast("Please choose a JPEG or PNG photo.");
      }, 0);
      return;
    }
    var old = draft.photos[slotIndex];
    if (old && old.objectUrl) URL.revokeObjectURL(old.objectUrl);
    var p = {
      id: newId(10),
      name: file.name || "photo.jpg",
      type: file.type || "image/jpeg",
      bytes: file.size,
      file: file,
      objectUrl: URL.createObjectURL(file),
      orientation: draft.size === "square" ? "square" : null,
      crop: null,
      thumb: "",
      origStatus: "pending",
      printStatus: "pending",
      progress: 0
    };
    draft.photos[slotIndex] = p;
    var probe = new Image();
    probe.onload = function () {
      p.w = probe.naturalWidth;
      p.h = probe.naturalHeight;
      if (!p.orientation) p.orientation = p.w > p.h ? "landscape" : "portrait";
      startOriginalUpload(p);
      renderSlots();
      openCropper(p);
    };
    probe.onerror = function () {
      draft.photos[slotIndex] = old || null;
      renderSlots();
      showToast("That photo couldn't be opened. Please try a different one.");
    };
    probe.src = p.objectUrl;
  }

  // ---------- cropper ----------
  function openCropper(p) {
    cropTarget = p;
    cropOrientation = p.orientation;
    var img = $("crop-img");
    if (cropper) { cropper.destroy(); cropper = null; }
    img.src = p.objectUrl;
    var orientBox = $("orient");
    orientBox.hidden = draft.size === "square";
    updateOrientChips();
    showSheet("cropper");
    // Wait a frame so the stage has its size before Cropper measures it.
    window.requestAnimationFrame(function () {
      cropper = new window.Cropper(img, {
        // The frame stays put; the customer moves and zooms the photo under it.
        viewMode: 1,
        dragMode: "move",
        aspectRatio: aspectFor(cropOrientation),
        autoCropArea: 0.92,
        movable: true,
        zoomable: true,
        zoomOnTouch: true,
        zoomOnWheel: true,
        wheelZoomRatio: 0.08,
        cropBoxMovable: false,
        cropBoxResizable: false,
        scalable: false,
        rotatable: true,
        guides: false,
        center: false,
        highlight: false,
        background: false,
        checkOrientation: false,
        toggleDragModeOnDblclick: false,
        data: p.crop ? { x: p.crop.x, y: p.crop.y, width: p.crop.width, height: p.crop.height, rotate: p.crop.rotate } : undefined,
        ready: function () {
          rememberMinZoom();
          drawGuide();
          checkResolution();
        },
        zoom: function (e) {
          // Don't let the photo shrink smaller than the frame.
          if (e.detail.ratio < e.detail.oldRatio && e.detail.ratio < minZoom * 0.999) {
            e.preventDefault();
            if (cropper && e.detail.oldRatio > minZoom) cropper.zoomTo(minZoom);
          }
        },
        crop: checkResolution
      });
    });
  }

  var minZoom = 0;
  function rememberMinZoom() {
    if (!cropper) return;
    var c = cropper.getCanvasData();
    var img = cropper.getImageData();
    minZoom = img.naturalWidth ? c.width / img.naturalWidth : 0;
  }

  function resetFrame() {
    if (!cropper) return;
    cropper.reset();
    cropper.setAspectRatio(aspectFor(cropOrientation));
    rememberMinZoom();
    drawGuide();
  }

  function aspectFor(orientation) {
    var mm = paperMm(draft.size, orientation);
    return mm[0] / mm[1];
  }

  function drawGuide() {
    var box = document.querySelector("#cropper .cropper-crop-box");
    if (!box) return;
    var layer = box.querySelector(".wrap-layer");
    if (!layer) {
      layer = el("div", "wrap-layer");
      layer.appendChild(el("div", "face-guide"));
      box.appendChild(layer);
    }
    var paper = paperMm(draft.size, cropOrientation);
    var face = faceMm(draft.size, cropOrientation);
    var gx = ((paper[0] - face[0]) / 2 / paper[0]) * 100;
    var gy = ((paper[1] - face[1]) / 2 / paper[1]) * 100;
    var g = layer.querySelector(".face-guide");
    g.style.left = gx + "%";
    g.style.right = gx + "%";
    g.style.top = gy + "%";
    g.style.bottom = gy + "%";
  }

  function checkResolution() {
    if (!cropper) return;
    var d = cropper.getData();
    var mm = paperMm(draft.size, cropOrientation);
    var dpi = d.width / (mm[0] / 25.4);
    $("crop-warning").hidden = !(dpi > 0 && dpi < 150);
  }

  function updateOrientChips() {
    Array.prototype.forEach.call(document.querySelectorAll("#orient .chip"), function (b) {
      b.classList.toggle("active", b.getAttribute("data-orient") === cropOrientation);
      b.setAttribute("aria-pressed", b.getAttribute("data-orient") === cropOrientation ? "true" : "false");
    });
  }

  function setOrientation(o) {
    if (!cropper || draft.size === "square" || o === cropOrientation) return;
    cropOrientation = o;
    resetFrame();
    updateOrientChips();
  }

  function closeCropper() {
    if (cropper) { cropper.destroy(); cropper = null; }
    cropTarget = null;
    hideSheet("cropper");
  }

  function finishCrop() {
    if (!cropper || !cropTarget) return;
    var p = cropTarget;
    var d = cropper.getData(true);
    // Print file keeps the photo's full resolution inside the crop.
    // Never below 300 dpi at paper size; capped at 16 MP because iPhone
    // Safari can't draw a bigger canvas.
    var px = printPx(draft.size, cropOrientation);
    var scale = Math.max(1, d.width / px[0]);
    scale = Math.min(scale, Math.sqrt(16000000 / (px[0] * px[1])));
    var canvas = cropper.getCroppedCanvas({
      width: Math.round(px[0] * scale),
      height: Math.round(px[1] * scale),
      fillColor: "#ffffff",
      imageSmoothingEnabled: true,
      imageSmoothingQuality: "high"
    });
    if (!canvas) { showToast("Couldn't crop that photo. Please try again."); return; }
    p.orientation = cropOrientation;
    p.scottCrops = false;
    p.crop = { x: d.x, y: d.y, width: d.width, height: d.height, rotate: d.rotate || 0 };
    // Small thumbnail for the cart.
    var t = document.createElement("canvas");
    var scale = 200 / Math.max(canvas.width, canvas.height);
    t.width = Math.round(canvas.width * scale);
    t.height = Math.round(canvas.height * scale);
    t.getContext("2d").drawImage(canvas, 0, 0, t.width, t.height);
    p.thumb = t.toDataURL("image/jpeg", 0.75);
    canvas.toBlob(function (blob) {
      if (!blob) { p.printStatus = "error"; renderSlots(); return; }
      p.printBlob = blob;
      startPrintUpload(p);
    }, "image/jpeg", 0.92);
    closeCropper();
    renderSlots();
  }

  // Customer can't get the crop right: send the original and let Scott crop it.
  function letScottCrop() {
    if (!cropTarget) return;
    var p = cropTarget;
    p.orientation = cropOrientation;
    p.scottCrops = true;
    p.crop = null;
    p.printPath = "";
    p.printBlob = null;
    p.printStatus = "done";
    p.printBytes = 0;
    p.printSent = 0;
    var img = $("crop-img");
    var t = document.createElement("canvas");
    var w = img.naturalWidth || 1, h = img.naturalHeight || 1;
    var scale = 200 / Math.max(w, h);
    t.width = Math.max(1, Math.round(w * scale));
    t.height = Math.max(1, Math.round(h * scale));
    t.getContext("2d").drawImage(img, 0, 0, t.width, t.height);
    p.thumb = t.toDataURL("image/jpeg", 0.75);
    closeCropper();
    updateProgress(p);
    renderSlots();
  }

  // ---------- uploads ----------
  function safeName(name) {
    return String(name).replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^-+|-+$/g, "").slice(-60) || "photo.jpg";
  }

  function updateProgress(p) {
    var o = p.origBytes ? (p.origSent || 0) / p.origBytes : 0;
    var pr = p.printBytes ? (p.printSent || 0) / p.printBytes : 0;
    var total = (p.origBytes || 0) + (p.printBytes || 0);
    p.progress = total ? ((p.origSent || 0) + (p.printSent || 0)) / total : Math.max(o, pr);
    if (draft && draft.photos.indexOf(p) >= 0) renderSlots();
  }

  function startOriginalUpload(p) {
    p.origStatus = "uploading";
    p.origBytes = p.bytes;
    p.origSent = 0;
    uploadFile(p, "original", p.file, safeName(p.name), function (sent) {
      p.origSent = sent; updateProgress(p);
    }).then(function (path) {
      p.originalPath = path; p.origStatus = "done"; p.origSent = p.origBytes; updateProgress(p);
    }, function (err) {
      p.origStatus = "error"; updateProgress(p); console.warn(err);
    });
  }

  function startPrintUpload(p) {
    p.printStatus = "uploading";
    p.printBytes = p.printBlob.size;
    p.printSent = 0;
    var base = safeName(p.name).replace(/\.[^.]+$/, "");
    uploadFile(p, "print", p.printBlob, base + ".jpg", function (sent) {
      p.printSent = sent; updateProgress(p);
    }).then(function (path) {
      p.printPath = path; p.printStatus = "done"; p.printSent = p.printBytes; updateProgress(p);
    }, function (err) {
      p.printStatus = "error"; updateProgress(p); console.warn(err);
    });
  }

  function retryUploads(p) {
    if (p.origStatus === "error") startOriginalUpload(p);
    if (p.printStatus === "error" && p.printBlob) startPrintUpload(p);
    renderSlots();
  }

  function uploadFile(p, kind, blob, filename, onProgress) {
    if (DEMO) return fakeUpload(blob, kind, filename, onProgress);
    return fetch(API + "get-upload-link.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ cart_id: cart.id, photo_id: p.id, kind: kind, filename: filename, bytes: blob.size })
    })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || !j.link) throw new Error(j.error || "Upload link failed");
          return j;
        });
      })
      .then(function (j) {
        return new Promise(function (resolve, reject) {
          var xhr = new XMLHttpRequest();
          xhr.open("POST", j.link);
          xhr.setRequestHeader("Content-Type", "application/octet-stream");
          xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded); };
          xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) resolve(j.path);
            else reject(new Error("Dropbox upload " + xhr.status + " " + xhr.responseText));
          };
          xhr.onerror = function () { reject(new Error("Network error during upload")); };
          xhr.send(blob);
        });
      });
  }

  function fakeUpload(blob, kind, filename, onProgress) {
    return new Promise(function (resolve) {
      var sent = 0;
      var step = Math.max(blob.size / 12, 1);
      var t = window.setInterval(function () {
        sent = Math.min(blob.size, sent + step);
        onProgress(sent);
        if (sent >= blob.size) {
          window.clearInterval(t);
          resolve("/Pending/" + cart.id + "/demo-" + kind + "-" + filename);
        }
      }, 120);
    });
  }

  // ---------- cart ----------
  function addDraftToCart() {
    if (!draft || !draft.photos.every(photoReady)) return;
    cart.items.push({
      id: newId(8),
      size: draft.size,
      pack: draft.pack,
      photos: draft.photos.map(function (p) {
        return {
          id: p.id, name: p.name, w: p.w, h: p.h, orientation: p.orientation,
          crop: p.crop, scott_crops: !!p.scottCrops,
          original_path: p.originalPath, print_path: p.printPath || "", thumb: p.thumb
        };
      })
    });
    draft.photos.forEach(function (p) { if (p.objectUrl) URL.revokeObjectURL(p.objectUrl); });
    draft = null;
    saveCart();
    hideSheet("builder");
    renderCart();
    showToast("Added to your cart.");
    $("cart").scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function cartSubtotal() {
    return cart.items.reduce(function (sum, it) {
      var price = catalog && catalog.sizes[it.size] ? packPrice(it.size, it.pack) : 0;
      return sum + (price || 0);
    }, 0);
  }

  function renderCart() {
    if (!catalog) return;
    // Drop anything that no longer matches the catalog.
    cart.items = cart.items.filter(function (it) {
      return catalog.sizes[it.size] && packPrice(it.size, it.pack) && it.photos.length === it.pack;
    });
    var list = $("cart-items");
    list.innerHTML = "";
    cart.items.forEach(function (it, idx) {
      var row = el("div", "cart-item");
      var left = el("div");
      left.appendChild(el("div", "cart-item-title", packName(it.size, it.pack)));
      left.appendChild(el("div", "text-muted", sizeInfo(it.size).dimensions + " · " + it.pack + " photo" + (it.pack > 1 ? "s" : "")));
      row.appendChild(left);
      var side = el("div", "cart-item-side");
      side.appendChild(el("span", null, money(packPrice(it.size, it.pack))));
      var rm = el("button", "link-btn", "Remove");
      rm.type = "button";
      rm.addEventListener("click", function () {
        cart.items.splice(idx, 1);
        saveCart();
        renderCart();
      });
      side.appendChild(rm);
      row.appendChild(side);
      var thumbs = el("div", "cart-thumbs");
      it.photos.forEach(function (p, j) {
        if (!p.thumb) return;
        var im = el("img");
        im.src = p.thumb;
        im.alt = "Magnet " + (j + 1);
        thumbs.appendChild(im);
      });
      row.appendChild(thumbs);
      list.appendChild(row);
    });
    var has = cart.items.length > 0;
    $("cart-empty").hidden = has;
    $("cart-summary").hidden = !has;
    var sub = cartSubtotal();
    $("cart-subtotal").textContent = money(sub);
    var pickup = cart.delivery === "pickup";
    var ship = pickup ? 0 : catalog.shipping_cents;
    $("cart-shipping-label").textContent = pickup ? "Pickup in Cary" : "Shipping";
    $("cart-shipping").textContent = pickup ? "Free" : money(ship);
    $("cart-total").textContent = money(sub + ship);
    $("pickup-note").hidden = !pickup;
    Array.prototype.forEach.call(document.querySelectorAll('input[name="delivery"]'), function (r) {
      r.checked = r.value === (pickup ? "pickup" : "ship");
    });
    var count = cart.items.reduce(function (n, it) { return n + it.pack; }, 0);
    var badge = $("cart-count");
    badge.hidden = count === 0;
    badge.textContent = String(count);
  }

  function checkout() {
    var btn = $("checkout-btn");
    var err = $("checkout-error");
    err.hidden = true;
    if (!cart.items.length) return;
    btn.disabled = true;
    btn.textContent = "Starting checkout…";
    var payload = {
      cart_id: cart.id,
      delivery: cart.delivery === "pickup" ? "pickup" : "ship",
      items: cart.items.map(function (it) {
        return {
          size: it.size,
          pack: it.pack,
          photos: it.photos.map(function (p) {
            return {
              id: p.id, name: p.name, w: p.w, h: p.h, orientation: p.orientation,
              crop: p.crop, scott_crops: !!p.scott_crops,
              original_path: p.original_path, print_path: p.print_path || ""
            };
          })
        };
      })
    };
    if (DEMO) {
      window.setTimeout(function () {
        btn.disabled = false;
        btn.textContent = "Check out securely";
        err.hidden = false;
        err.textContent = "Test preview: on the real site this goes to Stripe's secure checkout page for " +
          money(cartSubtotal() + (cart.delivery === "pickup" ? 0 : catalog.shipping_cents)) + " plus NC tax if it applies.";
      }, 600);
      return;
    }
    fetch(API + "create-checkout.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
      .then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
      })
      .then(function (res) {
        if (res.ok && res.body.url) {
          storageSet(LAST_ORDER_KEY, JSON.stringify({ id: cart.id, at: Date.now() }));
          window.location.href = res.body.url;
          return;
        }
        if (res.status === 409) {
          // This cart was already paid for. Start fresh.
          cart = { id: newId(20), items: [] };
          saveCart();
          renderCart();
        }
        throw new Error(res.body.error || "Checkout failed");
      })
      .catch(function (e) {
        btn.disabled = false;
        btn.textContent = "Check out securely";
        err.hidden = false;
        err.textContent = e.message && e.message !== "Failed to fetch"
          ? e.message
          : "Something went wrong starting checkout. Please try again, or email scott@themagnetfairy.com.";
      });
  }

  // ---------- sheets + toast ----------
  var lastFocus = null;
  function showSheet(id) {
    lastFocus = document.activeElement;
    $(id).hidden = false;
    document.body.style.overflow = "hidden";
    var panel = $(id).querySelector(".sheet-panel");
    if (panel) panel.scrollTop = 0;
  }
  function hideSheet(id) {
    $(id).hidden = true;
    if ($("builder").hidden && $("cropper").hidden) document.body.style.overflow = "";
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* ignore */ } }
  }

  var toastTimer = null;
  function showToast(msg) {
    var t = $("toast");
    if (!t) {
      t = el("div", "toast");
      t.id = "toast";
      t.setAttribute("role", "status");
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add("show");
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () { t.classList.remove("show"); }, 3200);
  }

  // ---------- wire up ----------
  document.addEventListener("DOMContentLoaded", function () {
    if (DEMO) $("demo-banner").hidden = false;

    $("file-input").addEventListener("change", function (e) {
      onFileChosen(e.target.files && e.target.files[0]);
    });
    $("builder-close").addEventListener("click", function () { hideSheet("builder"); });
    $("builder-add").addEventListener("click", addDraftToCart);
    $("crop-cancel").addEventListener("click", function () {
      var p = cropTarget;
      closeCropper();
      // A brand-new photo that was never cropped: clear the slot again.
      if (p && !p.crop && draft) {
        var i = draft.photos.indexOf(p);
        if (i >= 0) { draft.photos[i] = null; renderSlots(); }
      }
    });
    $("crop-done").addEventListener("click", finishCrop);
    $("crop-rotate").addEventListener("click", function () {
      if (!cropper) return;
      cropper.rotate(90);
      cropper.setAspectRatio(aspectFor(cropOrientation));
      rememberMinZoom();
      drawGuide();
    });
    $("zoom-in").addEventListener("click", function () { if (cropper) cropper.zoom(0.15); });
    $("zoom-out").addEventListener("click", function () { if (cropper) cropper.zoom(-0.15); });
    $("crop-skip").addEventListener("click", letScottCrop);
    $("crop-replace").addEventListener("click", function () {
      if (!draft || !cropTarget) return;
      slotIndex = draft.photos.indexOf(cropTarget);
      closeCropper();
      var input = $("file-input");
      input.value = "";
      input.click();
    });
    Array.prototype.forEach.call(document.querySelectorAll("#orient .chip"), function (b) {
      b.addEventListener("click", function () { setOrientation(b.getAttribute("data-orient")); });
    });
    $("checkout-btn").addEventListener("click", checkout);
    Array.prototype.forEach.call(document.querySelectorAll('input[name="delivery"]'), function (r) {
      r.addEventListener("change", function () {
        if (!r.checked) return;
        cart.delivery = r.value === "pickup" ? "pickup" : "ship";
        saveCart();
        renderCart();
      });
    });
    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      if (!$("cropper").hidden) $("crop-cancel").click();
      else if (!$("builder").hidden) hideSheet("builder");
    });

    if (params.has("canceled")) {
      showToast("Checkout canceled. Your cart is still here.");
    }

    loadCatalog()
      .then(function (c) {
        catalog = c;
        renderProducts();
        renderCart();
      })
      .catch(function () {
        $("products").innerHTML = "";
        $("products").appendChild(el("p", "form-error",
          "The shop couldn't load. Please refresh, or email scott@themagnetfairy.com to order."));
      });
  });

  // Exposed for the thanks page and tests.
  window.MagnetShop = {
    clearCart: function () {
      storageRemove(STORE_KEY);
    },
    isDemo: DEMO
  };
})();
