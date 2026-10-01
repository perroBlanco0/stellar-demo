<?php declare(strict_types=1);

namespace App;

// Envio de correo transaccional de marca (Cosigna).
// Config por env:
//   BREVO_API_KEY   — clave de brevo.com (prioritaria; xkeysib-...)
//   RESEND_API_KEY  — clave de resend.com (respaldo si no hay Brevo)
//   CORREO_DESDE    — remitente, ej. "Cosigna <hola@tudominio.cl>"
//   CORREO_LOGO_URL — URL publica del logo (default: el logo del propio panel)
//   CORREO_APP_URL  — base del panel para los botones (default: el panel en Render)
// Nunca rompe el request: si el envio falla devuelve false y el flujo sigue.
final class Correo
{
    public static function enviar(string $para, string $asunto, string $cuerpoHtml): bool
    {
        $html = self::plantilla($cuerpoHtml);

        if (($_ENV['BREVO_API_KEY'] ?? '') !== '') {
            return self::enviarBrevo($para, $asunto, $html);
        }
        if (($_ENV['RESEND_API_KEY'] ?? '') !== '') {
            return self::enviarResend($para, $asunto, $html);
        }
        return false;
    }

    // api.brevo.com/v3/smtp/email — el remitente debe estar verificado en Brevo.
    private static function enviarBrevo(string $para, string $asunto, string $html): bool
    {
        [$nombre, $email] = self::remitente();
        $payload = json_encode([
            'sender' => ['name' => $nombre, 'email' => $email],
            'to' => [['email' => $para]],
            'subject' => $asunto,
            'htmlContent' => $html,
        ]);

        return self::post(
            'https://api.brevo.com/v3/smtp/email',
            "api-key: {$_ENV['BREVO_API_KEY']}\r\nContent-Type: application/json\r\n",
            $payload,
            'messageId'
        );
    }

    private static function enviarResend(string $para, string $asunto, string $html): bool
    {
        [$nombre, $email] = self::remitente();
        $payload = json_encode([
            'from' => "{$nombre} <{$email}>",
            'to' => [$para],
            'subject' => $asunto,
            'html' => $html,
        ]);

        return self::post(
            'https://api.resend.com/emails',
            "Authorization: Bearer {$_ENV['RESEND_API_KEY']}\r\nContent-Type: application/json\r\n",
            $payload,
            'id'
        );
    }

    // "Cosigna <hola@x.cl>" -> ['Cosigna', 'hola@x.cl']
    private static function remitente(): array
    {
        $desde = $_ENV['CORREO_DESDE'] ?? 'Cosigna <onboarding@resend.dev>';
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $desde, $m)) {
            return [$m[1] !== '' ? $m[1] : 'Cosigna', $m[2]];
        }
        return ['Cosigna', $desde];
    }

    private static function post(string $url, string $headers, string $payload, string $campoOk): bool
    {
        $contexto = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $respuesta = @file_get_contents($url, false, $contexto);
            if ($respuesta === false) {
                return false;
            }
            $datos = json_decode($respuesta, true);
            return isset($datos[$campoOk]);
        } catch (\Throwable) {
            return false;
        }
    }

    // Correos de un gasto aprobado/ejecutado: miembros con usuario registrado
    // (email) + admins de la organizacion de la caja. Devuelve cuantos salieron.
    public static function avisarGastoAprobado(PDO $pdo, int $cajaId, array $propuesta): int
    {
        $destinos = [];

        $stmt = $pdo->prepare(
            'SELECT u.email FROM members m JOIN usuarios u ON u.id = m.usuario_id
             WHERE m.caja_id = ? AND u.email IS NOT NULL'
        );
        $stmt->execute([$cajaId]);
        foreach ($stmt->fetchAll() as $fila) {
            $destinos[$fila['email']] = true;
        }

        $stmt = $pdo->prepare(
            'SELECT a.email FROM admin_users a JOIN cajas c ON c.organizacion_id = a.organizacion_id
             WHERE c.id = ?'
        );
        $stmt->execute([$cajaId]);
        foreach ($stmt->fetchAll() as $fila) {
            $destinos[$fila['email']] = true;
        }

        $cajaStmt = $pdo->prepare('SELECT nombre FROM cajas WHERE id = ?');
        $cajaStmt->execute([$cajaId]);
        $caja = $cajaStmt->fetch();
        $nombreCaja = $caja ? $caja['nombre'] : "caja #{$cajaId}";

        $monto = htmlspecialchars((string) ($propuesta['monto'] ?? ''), ENT_QUOTES);
        $motivo = htmlspecialchars((string) ($propuesta['motivo'] ?? ''), ENT_QUOTES);
        $nombre = htmlspecialchars($nombreCaja, ENT_QUOTES);

        $enviados = 0;
        foreach (array_keys($destinos) as $para) {
            $cuerpo = "
                <p style='margin:0 0 16px;color:#111827;font-size:15px;line-height:1.6'>
                    Un gasto de tu caja <strong>{$nombre}</strong> fue aprobado por todos
                    los miembros requeridos y ya se ejecutó.
                </p>
                <table style='width:100%;border-collapse:collapse;font-size:14px;color:#374151'>
                    <tr><td style='padding:8px 0;color:#6b7280'>Monto</td>
                        <td style='padding:8px 0;text-align:right;font-weight:600'>{$monto}</td></tr>
                    <tr><td style='padding:8px 0;color:#6b7280'>Motivo</td>
                        <td style='padding:8px 0;text-align:right'>{$motivo}</td></tr>
                </table>
                " . self::boton('Ver la caja en Cosigna', "/#/caja/{$cajaId}");
            if (self::enviar($para, "Gasto aprobado — {$nombreCaja}", $cuerpo)) {
                $enviados++;
            }
        }

        return $enviados;
    }

    public static function bienvenida(string $para, string $nombre): bool
    {
        $nombre = htmlspecialchars($nombre, ENT_QUOTES);
        $cuerpo = "
            <p style='margin:0 0 16px;color:#111827;font-size:15px;line-height:1.6'>
                Hola <strong>{$nombre}</strong>, tu cuenta en Cosigna quedó lista.
            </p>
            <p style='margin:0 0 16px;color:#374151;font-size:14px;line-height:1.6'>
                Entra con tu correo, revisa las cajas donde eres miembro y aprueba
                los gastos pendientes con un clic.
            </p>
            " . self::boton('Entrar a Cosigna', '/#/');

        return self::enviar($para, 'Bienvenido a Cosigna', $cuerpo);
    }

    public static function codigoRecuperacion(string $para, string $codigo): bool
    {
        $codigo = htmlspecialchars($codigo, ENT_QUOTES);
        $cuerpo = "
            <p style='margin:0 0 16px;color:#111827;font-size:15px;line-height:1.6'>
                Usa este código para cambiar tu clave:
            </p>
            <p style='margin:0 0 16px;text-align:center;font-size:32px;font-weight:700;
                      letter-spacing:8px;color:#2563eb'>{$codigo}</p>
            <p style='margin:0 0 16px;color:#6b7280;font-size:13px'>
                Vence en 15 minutos. Si no lo pediste tú, ignora este correo.
            </p>
            " . self::boton('Cambiar mi clave', '/#/recuperar');

        return self::enviar($para, 'Tu código de Cosigna', $cuerpo);
    }

    // Boton de accion con link al panel.
    private static function boton(string $texto, string $ruta): string
    {
        $app = rtrim($_ENV['CORREO_APP_URL'] ?? 'https://stellar-demo-frontend.onrender.com', '/');
        $url = htmlspecialchars($app . $ruta, ENT_QUOTES);
        $texto = htmlspecialchars($texto, ENT_QUOTES);
        return "<p style='margin:0;text-align:center'>
            <a href='{$url}' style='display:inline-block;padding:12px 28px;background:#2563eb;
               color:#ffffff;font-size:14px;font-weight:600;border-radius:10px;
               text-decoration:none'>{$texto}</a>
        </p>";
    }

    // Marco de marca: fondo neutro, tarjeta blanca, acento azul del panel.
    private static function plantilla(string $cuerpo): string
    {
        $logo = $_ENV['CORREO_LOGO_URL'] ?? 'https://stellar-demo-frontend.onrender.com/logo.png';
        $logo = htmlspecialchars($logo, ENT_QUOTES);

        return "<!doctype html>
<html><body style='margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif'>
  <div style='max-width:520px;margin:32px auto;padding:0 16px'>
    <div style='background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)'>
      <div style='padding:20px 24px;border-bottom:1px solid #f4f4f5'>
        <img src='{$logo}' alt='Cosigna' width='28' height='28'
             style='display:inline-block;vertical-align:middle;border:0' />
        <span style='font-size:17px;font-weight:700;color:#111827;letter-spacing:-.3px;
                     vertical-align:middle;margin-left:8px'>Cosigna</span>
        <span style='font-size:12px;color:#6b7280;margin-left:8px;vertical-align:middle'>tesorerías colectivas</span>
      </div>
      <div style='padding:24px'>{$cuerpo}</div>
      <div style='padding:16px 24px;background:#fafafa;border-top:1px solid #f4f4f5;
                  font-size:12px;color:#9ca3af'>
        Cosigna · aprobación compartida sobre Stellar
      </div>
    </div>
  </div>
</body></html>";
    }
}
