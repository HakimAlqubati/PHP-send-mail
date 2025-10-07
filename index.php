<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Contact Form</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body{font-family:'Poppins',Arial,sans-serif;background:linear-gradient(135deg,#dfe9f3,#fff);display:flex;justify-content:center;align-items:center;height:100vh;margin:0}
    .contact-form{background:#fff;padding:30px 40px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.1);width:100%;max-width:420px}
    .contact-form h2{text-align:center;margin-bottom:25px;color:#333;letter-spacing:1px}
    .contact-form label{font-weight:600;color:#444;display:block;margin-bottom:8px}
    .contact-form input,.contact-form textarea{width:100%;padding:12px 14px;border:1px solid #ccc;border-radius:10px;font-size:15px;outline:none;transition:border-color .3s,box-shadow .3s}
    .contact-form input:focus,.contact-form textarea:focus{border-color:#007bff;box-shadow:0 0 8px rgba(0,123,255,.2)}
    .contact-form textarea{resize:none;min-height:120px}
    .contact-form button{width:100%;padding:12px;margin-top:15px;border:none;border-radius:10px;background:linear-gradient(135deg,#007bff,#0056b3);color:#fff;font-size:16px;font-weight:600;cursor:pointer;transition:all .3s}
    .contact-form button:hover{background:linear-gradient(135deg,#0056b3,#004099);transform:translateY(-2px);box-shadow:0 5px 12px rgba(0,0,0,.15)}
    @media (max-width:500px){.contact-form{padding:25px}}
    /* honey-pot field hidden from users */
    .hp{position:absolute;left:-9999px;opacity:0;visibility:hidden}
    .msg{margin-top:12px;font-size:14px}
    .msg.ok{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:8px;border-radius:8px}
    .msg.err{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:8px;border-radius:8px}
  </style>
</head>
<body>
  <form class="contact-form" id="contactForm" action="send.php" method="POST" novalidate>
    <h2>Contact Us</h2>

    <!-- CSRF token (يبنى في send.php عند عرض الصفحة إن رغبت) -->
    <input type="hidden" name="csrf" id="csrf" value="<?php echo bin2hex(random_bytes(16)); ?>">

    <!-- HoneyPot ضد البوتات -->
    <div class="hp">
      <label for="website">Website</label>
      <input type="text" id="website" name="website" autocomplete="off">
    </div>

    <label for="name">Name</label>
    <input type="text" id="name" name="name" required minlength="2" maxlength="100">

    <label for="email">Email</label>
    <input type="email" id="email" name="email" required maxlength="150">

    <label for="message">Message</label>
    <textarea id="message" name="message" required minlength="5" maxlength="5000"></textarea>

    <button type="submit">Send Message</button>
    <div id="formMsg" class="msg" style="display:none;"></div>
  </form>

  <!-- ملاحظة: إذا تبغى تحويل Redirect بدلاً من AJAX احذف السكربت -->
  <script>
    // إرسال AJAX اختياريًا ليبقى على نفس الصفحة
    const f = document.getElementById('contactForm');
    const m = document.getElementById('formMsg');

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      m.style.display = 'none';
      const fd = new FormData(f);

      try {
        const res = await fetch(f.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }});
        const isJSON = res.headers.get('content-type')?.includes('application/json');
        if (!res.ok) {
          const txt = isJSON ? (await res.json()).message : await res.text();
          throw new Error(txt || 'Request failed');
        }
        const data = isJSON ? await res.json() : { message: await res.text() };
        m.className = 'msg ok';
        m.textContent = data.message || 'Sent successfully.';
        m.style.display = 'block';
        f.reset();
      } catch (err) {
        m.className = 'msg err';
        m.textContent = (err && err.message) ? err.message : 'Error sending message.';
        m.style.display = 'block';
      }
    });
  </script>
</body>
</html>
