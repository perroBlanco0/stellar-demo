// La "plomería" invisible: genera claves, activa cuentas y firma operaciones
// en el navegador. Nada de esto se muestra al usuario.
const S = window.StellarSdk;
const HORIZON = 'https://horizon-testnet.stellar.org';
const FRIENDBOT = 'https://friendbot.stellar.org/?addr=';

function servidor() {
  return new S.Horizon.Server(HORIZON);
}

export function sdkListo() {
  return !!window.StellarSdk;
}

export function generarClaves() {
  const k = S.Keypair.random();
  return { publica: k.publicKey(), secreta: k.secret() };
}

export function clavePublicaValida(v) {
  try {
    return S.StrKey.isValidEd25519PublicKey(v);
  } catch (e) {
    return false;
  }
}

export function claveSecretaValida(v) {
  try {
    S.Keypair.fromSecret(v);
    return true;
  } catch (e) {
    return false;
  }
}

// Crea y fondea la cuenta de la caja (testnet). Devuelve true si salió bien.
export async function activarCuenta(pub) {
  try {
    const res = await fetch(FRIENDBOT + pub);
    return res.ok;
  } catch (e) {
    return false;
  }
}

export function cargarCuenta(pub) {
  return servidor().loadAccount(pub);
}

// Deja la caja configurada: los pagos exigen `umbral` aprobaciones de
// miembros; la clave maestra (peso 1) queda solo para administrarla.
export async function prepararCaja(pub, secreta, umbral) {
  const cuenta = await cargarCuenta(pub);
  const tx = new S.TransactionBuilder(cuenta, {
    fee: S.BASE_FEE,
    networkPassphrase: S.Networks.TESTNET,
  })
    .addOperation(
      S.Operation.setOptions({
        lowThreshold: 0,
        medThreshold: umbral,
        highThreshold: 1,
        masterWeight: 1,
      })
    )
    .setTimeout(30)
    .build();
  tx.sign(S.Keypair.fromSecret(secreta));
  await servidor().submitTransaction(tx);
}

// Inscribe la clave de un miembro como aprobador de la caja (peso 1).
export async function agregarFirmante(cajaPub, cajaSecreta, miembroPub) {
  const cuenta = await cargarCuenta(cajaPub);
  const tx = new S.TransactionBuilder(cuenta, {
    fee: S.BASE_FEE,
    networkPassphrase: S.Networks.TESTNET,
  })
    .addOperation(
      S.Operation.setOptions({
        signer: { ed25519PublicKey: miembroPub, weight: 1 },
      })
    )
    .setTimeout(30)
    .build();
  tx.sign(S.Keypair.fromSecret(cajaSecreta));
  await servidor().submitTransaction(tx);
}

// Arma el comprobante de gasto sin aprobar (el XDR que se guarda en proposals).
export async function construirXdrPago(cajaPub, destino, monto, codigoActivo, emisorActivo) {
  const cuenta = await cargarCuenta(cajaPub);
  const activo =
    codigoActivo === 'XLM' ? S.Asset.native() : new S.Asset(codigoActivo, emisorActivo);

  // Si la cuenta de destino aún no existe, un pago XLM la crea (create_account).
  // Para otros activos el destino debe existir y tener la confianza activa.
  let existeDestino = true;
  try {
    await cargarCuenta(destino);
  } catch (e) {
    existeDestino = false;
  }
  if (!existeDestino && codigoActivo !== 'XLM') {
    throw new Error('destino_no_existe');
  }
  const operacion = existeDestino
    ? S.Operation.payment({ destination: destino, asset: activo, amount: String(monto) })
    : S.Operation.createAccount({ destination: destino, startingBalance: String(monto) });

  const tx = new S.TransactionBuilder(cuenta, {
    fee: S.BASE_FEE,
    networkPassphrase: S.Networks.TESTNET,
  })
    .addOperation(operacion)
    .setTimeout(60 * 60 * 24 * 30) // la solicitud puede esperar aprobaciones varios días
    .build();
  return tx.toXDR();
}

// Suma la aprobación de un miembro al comprobante y devuelve el XDR actualizado.
export function firmarXdr(xdr, secreta) {
  const tx = S.TransactionBuilder.fromXDR(xdr, S.Networks.TESTNET);
  tx.sign(S.Keypair.fromSecret(secreta));
  return tx.toXDR();
}

// Activos reales de la cuenta según la red (incluye el emisor de cada uno).
export async function activosDeCuenta(pub) {
  const cuenta = await cargarCuenta(pub);
  return cuenta.balances.map((b) =>
    b.asset_type === 'native'
      ? { codigo: 'XLM', emisor: '', balance: b.balance }
      : { codigo: b.asset_code, emisor: b.asset_issuer, balance: b.balance }
  );
}
