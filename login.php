<?php
require __DIR__ . '/auth.php';
$pdo = require __DIR__ . '/config/db.php';
$config = require __DIR__ . '/config/config.php';
$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

function is_admin_role(?string $role): bool
{
    return strtolower(trim((string)$role)) === 'admin';
}

$error = '';
$return = $_GET['return'] ?? '';

if (current_user()) {
    $user = current_user();
    $dest = is_admin_role($user['ruolo'] ?? '')
        ? $baseUrl . '/modules/dashboard/index.php'
        : $baseUrl . '/modules/clienti/clienti.php';
    header('Location: ' . $dest);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, nome, email, ruolo, password_hash, attivo FROM utenti WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    $hash = hash('sha256', $password);
    if ($user && (int)$user['attivo'] === 1 && hash_equals($user['password_hash'], $hash)) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'nome' => $user['nome'],
            'email' => $user['email'],
            'ruolo' => $user['ruolo'],
        ];
        _tdg_track_activity('login');
        if ($return !== '') {
            $dest = $return;
        } elseif (is_admin_role($user['ruolo'] ?? '') || (billing_user_email() !== '' && strtolower(trim($user['email'] ?? '')) === billing_user_email())) {
            $dest = $baseUrl . '/modules/dashboard/index.php';
        } else {
            $dest = $baseUrl . '/modules/clienti/clienti.php';
        }
        header('Location: ' . $dest);
        exit;
    }

    $error = 'Credenziali non valide.';
}

require __DIR__ . '/layouts/header.php';
?>
<style>
    body {
        background: radial-gradient(circle at 18% 15%, #2596be 0%, #ffffff 88%);
        min-height: 100vh;
        overflow: hidden;
        position: relative;
    }
    body::before,
    body::after {
        content: "";
        position: fixed;
        inset: -30vmax;
        pointer-events: none;
        background: repeating-radial-gradient(
            circle at 50% 50%,
            rgba(37, 150, 190, 0.00) 0 28px,
            rgba(37, 150, 190, 0.14) 29px 31px,
            rgba(255, 255, 255, 0.00) 32px 58px
        );
        transform-origin: 50% 50%;
        opacity: 0.45;
        animation: rippleToCenter 9s linear infinite;
        z-index: 0;
    }
    body::after {
        opacity: 0.30;
        animation-delay: -4.5s;
    }
    @keyframes rippleToCenter {
        0% {
            transform: scale(1.55);
            opacity: 0.00;
        }
        12% {
            opacity: 0.40;
        }
        85% {
            opacity: 0.30;
        }
        100% {
            transform: scale(0.88);
            opacity: 0.00;
        }
    }
    .wrap {
        position: relative;
        z-index: 1;
    }
    .app-header { display: none; }
    .app-main { padding-top: 30px; }
</style>
<div class="wrap">
    <div class="card" style="max-width:420px;margin:40px auto;padding:28px 26px;">
        <div style="text-align:center;margin-bottom:12px;">
            <img src="<?php echo $baseUrl; ?>/assets/brand/logo.svg" alt="CRM Fatturazione" style="max-width:240px;height:auto;">
        </div>
        <h2 style="text-align:center;margin:0 0 18px 0;">Accesso</h2>
        <?php if ($error): ?>
            <p class="muted" style="color:#b00020;"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post">
            <div class="grid">
                <div style="grid-column:1/-1;">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div style="grid-column:1/-1;">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
            </div>
            <button class="btn" type="submit">Accedi</button>
        </form>
        <p class="muted" style="font-size:12px;line-height:1.45;margin-top:16px;">
            Area riservata aziendale. L’accesso è consentito solo a personale autorizzato.
            I dati sono trattati nel rispetto del Reg. (UE) 2016/679 (GDPR) e della normativa privacy vigente.
            Ogni accesso è registrato per finalità di sicurezza e conformità.
        </p>
    </div>
</div>
    <?php
    require __DIR__ . '/layouts/footer.php';
    ?>
