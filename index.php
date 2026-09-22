<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal privado</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:,">
<style>
  :root{
    --bg:#0f0f0e;
    --card:#ffffff;
    --gold:#e9b73e;
    --gold-dark:#c99a2b;
    --text-dim:#9a9a9a;
    --border:#e4e0d4;
  }
  *{box-sizing:border-box;}
  html,body{height:100%;}
  body{
    margin:0;
    min-height:100vh;
    background:
      radial-gradient(1200px 600px at 50% -10%, #232320 0%, #0f0f0e 55%);
    font-family: 'Helvetica Neue', Arial, sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
  }
  .wrap{
    width:100%;
    max-width:420px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:34px;
  }
  .card{
    width:100%;
    background:var(--card);
    border-radius:16px;
    box-shadow:0 30px 60px -20px rgba(0,0,0,.55), 0 1px 0 rgba(255,255,255,.04) inset;
    padding:30px 30px 26px 30px;
  }
  h1{
    font-size:15px;
    margin:0 0 4px 0;
    color:#161615;
    font-weight:700;
  }
  .sub{
    font-size:12.5px;
    color:#8a8a8a;
    margin:0 0 20px 0;
  }
  form{display:flex;flex-direction:column;gap:12px;}
  label{
    font-size:11.5px;
    font-weight:600;
    color:#5a5a5a;
    letter-spacing:.2px;
    margin-bottom:5px;
    display:block;
  }
  input[type=email],
  input[type=text]{
    width:100%;
    padding:11px 13px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:14px;
    color:#1c1c1c;
    background:#fcfbf8;
    outline:none;
    transition:border-color .15s ease;
  }
  input:focus{border-color:var(--gold-dark);}
  .field{margin-bottom:2px;}
  .btn{
    width:100%;
    padding:12px 0;
    border:none;
    border-radius:8px;
    font-size:14px;
    font-weight:700;
    cursor:pointer;
    transition:filter .15s ease, transform .05s ease;
  }
  .btn:active{transform:translateY(1px);}
  .btn-primary{
    background:var(--gold);
    color:#1c1500;
    margin-top:6px;
  }
  .btn-primary:hover{filter:brightness(1.05);}
  .btn-primary:disabled{opacity:.6;cursor:default;}
  .btn-link{
    background:none;
    border:none;
    color:var(--text-dim);
    font-size:11.5px;
    text-decoration:underline;
    cursor:pointer;
    padding:0;
    width:auto;
    align-self:center;
    margin-top:4px;
  }
  .msg{
    font-size:12.5px;
    border-radius:8px;
    padding:10px 12px;
    margin-top:14px;
    display:none;
  }
  .msg.error{background:#fdeceb;color:#a5322a;display:block;}
  .msg.ok{background:#ecf6ee;color:#276a3d;display:block;}
  .hidden{display:none !important;}
  .spinner{
    width:14px;height:14px;
    border:2px solid rgba(0,0,0,.15);
    border-top-color:#1c1500;
    border-radius:50%;
    display:inline-block;
    animation:spin .7s linear infinite;
    vertical-align:-2px;
    margin-right:6px;
  }
  @keyframes spin{to{transform:rotate(360deg);}}
  .footer-note{
    font-size:10.5px;
    color:#5a5a58;
    text-align:center;
    line-height:1.5;
  }
</style>
</head>
<body>

  <div class="wrap">
    <div class="card">

      <!-- PASO 1: EMAIL -->
      <div id="panel-email">
        <h1>Iniciar sesión</h1>
        <p class="sub">Ingresá tu email y te mandamos un código de acceso.</p>

        <form id="email-form">
          <div class="field">
            <label for="email-input">Email</label>
            <input type="email" id="email-input" required autocomplete="email">
          </div>
          <button type="submit" class="btn btn-primary" id="email-submit">Enviar código</button>
        </form>

        <div class="msg" id="email-msg"></div>
      </div>

      <!-- PASO 2: CODIGO -->
      <div id="panel-code" class="hidden">
        <h1>Ingresá el código</h1>
        <p class="sub">Te lo enviamos por email, vence en 10 minutos.</p>

        <form id="code-form">
          <div class="field">
            <label for="code-input">Código</label>
            <input type="text" id="code-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
          </div>
          <button type="submit" class="btn btn-primary" id="code-submit">Ingresar</button>
        </form>
        <button type="button" class="btn-link" id="back-link">Usar otro email</button>

        <div class="msg" id="code-msg"></div>
      </div>

    </div>

    <p class="footer-note">Acceso privado y restringido.<br>© <span id="year"></span> — Todos los derechos reservados.</p>
  </div>

  <script>
    document.getElementById("year").textContent = new Date().getFullYear();

    const panelEmail = document.getElementById("panel-email");
    const panelCode = document.getElementById("panel-code");
    let currentEmail = "";

    function showMsg(el, text, ok = false) {
      el.textContent = text;
      el.className = "msg " + (ok ? "ok" : "error");
    }

    document.getElementById("email-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const btn = document.getElementById("email-submit");
      const msg = document.getElementById("email-msg");
      msg.style.display = "none";
      currentEmail = document.getElementById("email-input").value.trim();
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span>Enviando…';
      try {
        const res = await fetch("/api/request-code.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: currentEmail }),
        });
        const data = await res.json();
        if (data.ok) {
          panelEmail.classList.add("hidden");
          panelCode.classList.remove("hidden");
          document.getElementById("code-input").focus();
        } else {
          showMsg(msg, data.message);
        }
      } catch (err) {
        console.error(err);
        showMsg(msg, "Ocurrió un error. Intentá de nuevo.");
      } finally {
        btn.disabled = false;
        btn.textContent = "Enviar código";
      }
    });

    document.getElementById("code-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const btn = document.getElementById("code-submit");
      const msg = document.getElementById("code-msg");
      msg.style.display = "none";
      const code = document.getElementById("code-input").value.trim();
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span>Verificando…';
      try {
        const res = await fetch("/api/verify-code.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: currentEmail, code }),
        });
        const data = await res.json();
        if (data.ok) {
          window.location.replace(data.redirect || "/dashboard.php");
        } else {
          showMsg(msg, data.message);
        }
      } catch (err) {
        console.error(err);
        showMsg(msg, "Ocurrió un error. Intentá de nuevo.");
      } finally {
        btn.disabled = false;
        btn.textContent = "Ingresar";
      }
    });

    document.getElementById("back-link").addEventListener("click", () => {
      panelCode.classList.add("hidden");
      panelEmail.classList.remove("hidden");
      document.getElementById("code-msg").style.display = "none";
    });
  </script>
</body>
</html>
