import { Component, signal } from '@angular/core';
import { LoginComponent } from './features/auth/login/login.component';

@Component({
  imports: [LoginComponent],
  selector: 'app-root',
  styleUrl: './app.css',
  templateUrl: './app.html',
})
export class App {
  protected readonly title = signal('gober-teso-app');
}

