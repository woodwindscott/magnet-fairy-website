// The Magnet Fairy: shows each .promo-bar until its data-until date, then hides it.
(function () {
  var now = Date.now();
  Array.prototype.forEach.call(document.querySelectorAll(".promo-bar[data-until]"), function (bar) {
    var until = Date.parse(bar.getAttribute("data-until"));
    bar.hidden = !(until && now < until);
  });
})();
