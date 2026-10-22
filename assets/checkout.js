/* Booking form → order.php → Razorpay Checkout → verify.php → ticket page */
(function () {
  'use strict';
  var form = document.getElementById('order-form');
  if (!form) return;

  var price = parseInt(form.dataset.price, 10) || 0;
  var source = form.dataset.source || 'online';
  var qtyInput = form.querySelector('input[name="quantity"]');
  var stepper = form.querySelector('.stepper');
  var max = parseInt(stepper.dataset.max, 10) || 1;
  var totalEl = form.querySelector('[data-total]');
  var errorEl = form.querySelector('.form-error');
  var submitBtn = form.querySelector('button[type="submit"]');
  var csrf = form.querySelector('input[name="_csrf"]').value;
  var busy = false;

  function rupees(paise) {
    var r = paise / 100;
    return '₹' + r.toLocaleString('en-IN', { maximumFractionDigits: paise % 100 ? 2 : 0, minimumFractionDigits: paise % 100 ? 2 : 0 });
  }
  function clampQty() {
    var q = parseInt(qtyInput.value, 10);
    if (isNaN(q) || q < 1) q = 1;
    if (q > max) q = max;
    qtyInput.value = q;
    totalEl.textContent = rupees(price * q);
    stepper.querySelector('[data-step="-1"]').disabled = q <= 1;
    stepper.querySelector('[data-step="1"]').disabled = q >= max;
    return q;
  }
  stepper.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-step]');
    if (!btn) return;
    qtyInput.value = (parseInt(qtyInput.value, 10) || 1) + parseInt(btn.dataset.step, 10);
    clampQty();
  });
  qtyInput.addEventListener('input', clampQty);
  clampQty();

  function showError(msg, field) {
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
    form.querySelectorAll('.invalid').forEach(function (el) { el.classList.remove('invalid'); });
    if (field) {
      var input = form.querySelector('[name="' + field + '"]');
      if (input) { input.classList.add('invalid'); input.focus(); }
    }
  }
  function setBusy(on, label) {
    busy = on;
    submitBtn.disabled = on;
    submitBtn.classList.toggle('is-busy', on);
    if (label) submitBtn.querySelector('.btn-label').innerHTML = label;
  }
  function resetButton() {
    setBusy(false, 'Pay <span data-total>' + rupees(price * clampQty()) + '</span>');
    totalEl = form.querySelector('[data-total]');
  }

  function postJSON(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(data)
    }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected response. Please try again.' }; })
        .then(function (body) { body._status = r.status; return body; });
    });
  }

  // Quick client-side checks; the server re-checks everything.
  function clientValidate(d) {
    if (!d.name || d.name.trim().length < 2) return ['Please enter your full name.', 'name'];
    var digits = (d.phone || '').replace(/\D/g, '');
    if (digits.length < 10) return ['Please enter a valid mobile number.', 'phone'];
    var emailEl = form.querySelector('[name="email"]');
    if ((emailEl.required || d.email) && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email || '')) return ['Please enter a valid email.', 'email'];
    var extra = form.querySelector('[name="extra_value"]');
    if (extra && extra.required && !extra.value.trim()) return ['Please fill in all required fields.', 'extra_value'];
    var terms = form.querySelector('input[type="checkbox"][name="terms"]');
    if (terms && !terms.checked) return ['Please accept the terms.', 'terms'];
    return null;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    var fd = new FormData(form);
    var data = {
      name: fd.get('name'), email: fd.get('email') || '', phone: fd.get('phone'),
      quantity: clampQty(), extra_value: fd.get('extra_value') || '',
      terms: !!fd.get('terms'), website: fd.get('website') || '', source: source
    };
    var err = clientValidate(data);
    if (err) { showError(err[0], err[1]); return; }
    showError('');

    if (typeof window.Razorpay !== 'function') {
      showError('Payment window could not load. Check your internet connection and reload the page.');
      return;
    }

    setBusy(true, 'Starting payment…');
    postJSON('/order.php', data).then(function (res) {
      if (res.error) {
        var field = res.fields ? Object.keys(res.fields)[0] : null;
        showError(res.error, field);
        if (res.sold_out) setTimeout(function () { location.reload(); }, 2500);
        resetButton();
        return;
      }
      openCheckout(res);
    }).catch(function () {
      showError('Network problem. Please check your connection and try again.');
      resetButton();
    });
  });

  function openCheckout(o) {
    var rzp = new window.Razorpay({
      key: o.key_id,
      order_id: o.order_id,
      amount: o.amount,
      currency: o.currency,
      name: o.brand || o.event_name,
      description: o.event_name + ' · ' + o.description,
      prefill: { name: o.name, email: o.email, contact: '+' + o.phone },
      notes: { booking_ref: o.ref },
      theme: { color: o.color },
      handler: function (resp) {
        setBusy(true, 'Confirming payment…');
        postJSON('/verify.php', resp).then(function (res) {
          if (res.redirect) { location.href = res.redirect; return; }
          showError(res.error || 'Could not confirm the payment.');
          resetButton();
        }).catch(function () {
          // Payment went through at Razorpay; the webhook will issue the ticket.
          showError('Payment received but confirmation is slow. Your ticket will arrive on WhatsApp/email shortly. Reference: ' + o.ref);
          resetButton();
        });
      },
      modal: {
        ondismiss: function () {
          showError('Payment cancelled. You can try again.');
          resetButton();
        }
      }
    });
    rzp.on('payment.failed', function (resp) {
      var reason = resp && resp.error && resp.error.description;
      showError('Payment failed' + (reason ? ': ' + reason : '') + '. You can try again.');
    });
    rzp.open();
  }
})();
