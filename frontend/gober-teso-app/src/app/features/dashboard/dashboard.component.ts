import { Component, signal, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { StellarSignModalComponent, DisbursementData, WalletType } from './stellar-sign-modal/stellar-sign-modal.component';

export interface GovernanceRequest {
  id: string;
  orderNumber: string;
  date: string;
  time: string;
  concept: string;
  destination: string;
  amountClp: number;
  amountXlm: number;
  signedCount: number;
  requiredSignatures: number;
  status: 'pending' | 'in_progress' | 'completed';
  statusLabel: string;
  signers: { name: string; role: string; signed: boolean; signedAt?: string }[];
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, StellarSignModalComponent],
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.css']
})
export class DashboardComponent {
  private router = inject(Router);

  // Navigation tabs
  activeTab = signal<string>('boveda');
  
  // Vault data (CLP values)
  vaultAddress = signal<string>('G04C7X7KM2LL36PNAUPL374Z89E1985Z9QK21LLD89ZXB9Z0K');
  formattedVaultAddress = computed(() => {
    const addr = this.vaultAddress();
    return `${addr.substring(0, 4)} ... ${addr.substring(addr.length - 4)}`;
  });

  availableLiquidBalanceClp = signal<number>(124500000); // $124.500.000 CLP
  availableLiquidBalanceXlm = signal<number>(7450.00);

  assignedBudget2025Clp = signal<number>(350000000); // $350.000.000 CLP
  budgetRemainingPercent = signal<number>(64.4);

  lastVerifiedWithdrawalClp = signal<number>(8200000); // $8.200.000 CLP
  lastWithdrawalTx = signal<string>('#92e01');
  lastWithdrawalDate = signal<string>('14 Mar 2025');

  // Active Pending Disbursement
  pendingDisbursement = signal({
    concept: 'Anticipo Escenario y Sonido',
    category: 'Semana Cultural',
    amountClp: 15000000, // $15.000.000 CLP
    amountXlm: 900.00,
    supplier: 'Sonarizaciones del Valle S.A.',
    ruleDescription: 'Requiere 3 de 3 Firmas: Presidente, Tesorero y Asesor Legal',
    requiredSignatures: 3,
    currentSignatures: 2,
    orderId: 'ORD-2025-089'
  });

  // Recent Requests list
  requests = signal<GovernanceRequest[]>([
    {
      id: '1',
      orderNumber: '#ORD-2025-089',
      date: '18 Mar 2025',
      time: '16:45 hrs',
      concept: 'Anticipo Escenario y Sonido',
      destination: 'Semana Cultural',
      amountClp: 15000000,
      amountXlm: 900.00,
      signedCount: 2,
      requiredSignatures: 3,
      status: 'in_progress',
      statusLabel: '2/3 Firmas',
      signers: [
        { name: 'Dr. Alejandro Soto', role: 'Presidente', signed: true, signedAt: '18 Mar 15:30' },
        { name: 'Valeria Méndez', role: 'Tesorera Titular', signed: false },
        { name: 'Lic. Camilo Bravo', role: 'Asesor Legal', signed: true, signedAt: '18 Mar 16:10' }
      ]
    },
    {
      id: '2',
      orderNumber: '#ORD-2025-088',
      date: '17 Mar 2025',
      time: '14:20 hrs',
      concept: 'Materiales Concurso Robótica',
      destination: 'Fac. de Ingeniería',
      amountClp: 8450000,
      amountXlm: 507.00,
      signedCount: 0,
      requiredSignatures: 3,
      status: 'pending',
      statusLabel: '0/3 Pendiente',
      signers: [
        { name: 'Dr. Alejandro Soto', role: 'Presidente', signed: false },
        { name: 'Valeria Méndez', role: 'Tesorera Titular', signed: false },
        { name: 'Lic. Camilo Bravo', role: 'Asesor Legal', signed: false }
      ]
    },
    {
      id: '3',
      orderNumber: '#ORD-2025-087',
      date: '14 Mar 2025',
      time: '09:12 hrs',
      concept: 'Honorarios Talleristas Foro DDHH',
      destination: 'Comprobante #92e01',
      amountClp: 8200000,
      amountXlm: 492.00,
      signedCount: 3,
      requiredSignatures: 3,
      status: 'completed',
      statusLabel: '3/3 Completado',
      signers: [
        { name: 'Dr. Alejandro Soto', role: 'Presidente', signed: true, signedAt: '14 Mar 08:30' },
        { name: 'Valeria Méndez', role: 'Tesorera Titular', signed: true, signedAt: '14 Mar 08:55' },
        { name: 'Lic. Camilo Bravo', role: 'Asesor Legal', signed: true, signedAt: '14 Mar 09:10' }
      ]
    }
  ]);

  // UI States & Modals
  copiedAddress = signal<boolean>(false);
  isSigning = signal<boolean>(false);
  showSignModal = signal<boolean>(false);
  showRequestModal = signal<boolean>(false);
  selectedRequest = signal<GovernanceRequest | null>(null);
  toastMessage = signal<{ title: string; desc: string; type: 'success' | 'info' | 'error' } | null>(null);

  // Helper to format CLP
  formatClp(amount: number): string {
    return '$' + new Intl.NumberFormat('es-CL').format(amount) + ' CLP';
  }

  // Copy vault address
  copyAddress(): void {
    if (typeof navigator !== 'undefined' && navigator.clipboard) {
      navigator.clipboard.writeText(this.vaultAddress());
    }
    this.copiedAddress.set(true);
    this.showToast('Dirección Copiada', 'Clave pública de la bóveda copiada al portapapeles', 'info');
    setTimeout(() => this.copiedAddress.set(false), 2500);
  }

  // Navigation tab switcher
  setTab(tab: string): void {
    this.activeTab.set(tab);
  }

  // Open Sign Modal
  openSignDisbursementModal(): void {
    this.showSignModal.set(true);
  }

  closeSignModal(): void {
    this.showSignModal.set(false);
  }

  // Open Request Modal
  openRequestModal(): void {
    this.showRequestModal.set(true);
  }

  closeRequestModal(): void {
    this.showRequestModal.set(false);
  }

  // Open detail for a request
  openRequestDetail(req: GovernanceRequest): void {
    this.selectedRequest.set(req);
  }

  closeRequestDetail(): void {
    this.selectedRequest.set(null);
  }

  // Execute cryptographic signature
  executeSignature(event?: { wallet: WalletType }): void {
    const walletUsed = event?.wallet ? event.wallet.toUpperCase() : 'FREIGHTER';
    this.isSigning.set(true);

    setTimeout(() => {
      this.isSigning.set(false);
      this.showSignModal.set(false);

      // Update the active item to completed
      this.requests.update(list => {
        return list.map(item => {
          if (item.orderNumber === '#ORD-2025-089') {
            return {
              ...item,
              signedCount: 3,
              status: 'completed',
              statusLabel: '3/3 Completado',
              signers: item.signers.map(s => s.role.includes('Tesorera') ? { ...s, signed: true, signedAt: 'Ahora' } : s)
            };
          }
          return item;
        });
      });

      this.pendingDisbursement.update(prev => ({
        ...prev,
        currentSignatures: 3
      }));

      this.showToast(
        'Firma Criptográfica Estampada',
        `La transacción por $15.000.000 CLP fue firmada vía ${walletUsed}, alcanzó quórum (3/3) y fue despachada al Ledger Stellar.`,
        'success'
      );
    }, 1800);
  }

  // Send Signature Requests
  sendSignatureRequests(): void {
    this.showRequestModal.set(false);
    this.showToast(
      'Notificaciones Enviadas',
      'Se ha enviado solicitud de firma Ed25519 con payload Soroban a Presidente y Asesor Legal.',
      'info'
    );
  }

  // Toast Helper
  showToast(title: string, desc: string, type: 'success' | 'info' | 'error' = 'info'): void {
    this.toastMessage.set({ title, desc, type });
    setTimeout(() => {
      this.toastMessage.set(null);
    }, 4500);
  }

  // Logout & Return to Login
  logout(): void {
    this.router.navigate(['/login']);
  }
}
