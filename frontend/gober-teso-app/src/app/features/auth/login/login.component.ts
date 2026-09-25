import { Component, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule, ReactiveFormsModule],
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.css']
})
export class LoginComponent {
  loginForm: FormGroup;
  showPassword = signal<boolean>(false);
  isPasskeyEnabled = signal<boolean>(true);
  isLoading = signal<boolean>(false);
  isConnectingWallet = signal<boolean>(false);
  selectedWallet = signal<string | null>(null);
  showWalletModal = signal<boolean>(false);
  notification = signal<{ type: 'success' | 'error' | 'info'; message: string } | null>(null);

  readonly supportedWallets = [
    { id: 'freighter', name: 'Freighter', desc: 'Extensión oficial para navegador', icon: 'freighter' },
    { id: 'albedo', name: 'Albedo', desc: 'Firma web segura sin extensiones', icon: 'albedo' },
    { id: 'lobstr', name: 'LOBSTR', desc: 'Billetera móvil institucional', icon: 'lobstr' },
    { id: 'xbull', name: 'xBull Wallet', desc: 'Soporte multisig avanzado', icon: 'xbull' },
  ];

  constructor(private fb: FormBuilder) {
    this.loginForm = this.fb.group({
      identifier: ['tesorero@gobernanza.stellar', [Validators.required]],
      pin: ['12345678', [Validators.required, Validators.minLength(6)]]
    });
  }

  togglePasswordVisibility(): void {
    this.showPassword.update(prev => !prev);
  }

  togglePasskey(): void {
    this.isPasskeyEnabled.update(prev => !prev);
  }

  openWalletModal(specificWallet?: string): void {
    if (specificWallet) {
      this.selectedWallet.set(specificWallet);
    }
    this.showWalletModal.set(true);
  }

  closeWalletModal(): void {
    this.showWalletModal.set(false);
  }

  connectWallet(walletName: string): void {
    this.isConnectingWallet.set(true);
    this.notification.set({
      type: 'info',
      message: `Solicitando autorización de firma en ${walletName}...`
    });

    setTimeout(() => {
      this.isConnectingWallet.set(false);
      this.showWalletModal.set(false);
      this.notification.set({
        type: 'success',
        message: `Billetera ${walletName} vinculada con éxito. Sesión de tesorería autorizada.`
      });
    }, 1500);
  }

  onRecoverPin(): void {
    this.notification.set({
      type: 'info',
      message: 'Se ha enviado un enlace de recuperación criptográfica al correo oficial registrado.'
    });
  }

  onSubmit(): void {
    if (this.loginForm.invalid) {
      this.notification.set({
        type: 'error',
        message: 'Por favor complete todos los campos requeridos.'
      });
      return;
    }

    this.isLoading.set(true);
    this.notification.set(null);

    setTimeout(() => {
      this.isLoading.set(false);
      const passkeyStatus = this.isPasskeyEnabled() ? 'con validación de hardware HSM' : '';
      this.notification.set({
        type: 'success',
        message: `Autenticación institucional exitosa ${passkeyStatus}. Accediendo a la Bóveda de Gobernanza...`
      });
    }, 1400);
  }
}
