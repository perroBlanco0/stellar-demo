import { Component, EventEmitter, Input, Output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface MultiSigSigner {
  id: string;
  name: string;
  role: string;
  status: 'confirmed' | 'active_turn' | 'pending';
  statusLabel: string;
  badgeText: string;
  detailText: string;
  iconType: 'check' | 'key' | 'clock';
}

export type WalletType = 'freighter' | 'albedo' | 'lobstr';

export interface DisbursementData {
  amountClp: number;
  amountUsdc: number;
  amountXlm: number;
  concept: string;
  category: string;
  destinationAccount: string;
  sourceVaultAccount: string;
  orderNumber?: string;
  requiredSignatures: number;
  currentSignatures: number;
}

@Component({
  selector: 'app-stellar-sign-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './stellar-sign-modal.component.html',
  styleUrls: ['./stellar-sign-modal.component.css']
})
export class StellarSignModalComponent {
  @Input() isOpen: boolean = false;
  
  @Input() data: DisbursementData = {
    amountClp: 15000000,
    amountUsdc: 900.00,
    amountXlm: 7420.30,
    concept: 'Anticipo Sonido y Escenario - Semana Cultural',
    category: 'Semana Cultural',
    destinationAccount: 'GA5J...7PK4',
    sourceVaultAccount: 'G04C7X...89ZXB9Z0K',
    orderNumber: '#ORD-2025-089',
    requiredSignatures: 3,
    currentSignatures: 1
  };

  @Output() close = new EventEmitter<void>();
  @Output() signConfirmed = new EventEmitter<{ wallet: WalletType }>();
  @Output() rejected = new EventEmitter<void>();

  selectedWallet = signal<WalletType>('freighter');
  isSigning = signal<boolean>(false);
  copiedAccount = signal<boolean>(false);

  signersList: MultiSigSigner[] = [
    {
      id: 'presidencia',
      name: 'Presidencia',
      role: 'Vía Freighter Wallet',
      status: 'confirmed',
      statusLabel: 'CONFIRMADA',
      badgeText: '09:42:15 UTC ✓',
      detailText: '',
      iconType: 'check'
    },
    {
      id: 'tesoreria',
      name: 'Tesorera Titular',
      role: 'Valeria Méndez',
      status: 'active_turn',
      statusLabel: 'ES TU TURNO',
      badgeText: 'Esperando Tu Firma...',
      detailText: '',
      iconType: 'key'
    },
    {
      id: 'asesoria',
      name: 'Asesor / Vocal',
      role: 'Lic. Camilo Bravo',
      status: 'pending',
      statusLabel: 'AÚN EN ESPERA',
      badgeText: '3ra Firma en Cola',
      detailText: '',
      iconType: 'clock'
    }
  ];

  selectWallet(wallet: WalletType): void {
    if (!this.isSigning()) {
      this.selectedWallet.set(wallet);
    }
  }

  copyDestination(): void {
    if (typeof navigator !== 'undefined' && navigator.clipboard) {
      navigator.clipboard.writeText(this.data.destinationAccount);
    }
    this.copiedAccount.set(true);
    setTimeout(() => this.copiedAccount.set(false), 2000);
  }

  onSign(): void {
    this.isSigning.set(true);
    setTimeout(() => {
      this.isSigning.set(false);
      this.signConfirmed.emit({ wallet: this.selectedWallet() });
    }, 1500);
  }

  onCancel(): void {
    if (!this.isSigning()) {
      this.rejected.emit();
      this.close.emit();
    }
  }

  onBackdropClick(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('modal-backdrop')) {
      this.onCancel();
    }
  }
}
