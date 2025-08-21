# Koperasi Taksi POS

A web-based **Point of Sale (POS) & Management System** for a Taxi Cooperative with three roles: **Admin**, **CSO**, and **Supir**.

> **Stack:** HTML + TailwindCSS (CDN), vanilla JS (ES Modules), Chart.js (CDN), Heroicons via inline SVG.  
> **Auth & Data:** Demo-only localStorage (no backend).

## Quick Start

1. Download & extract the zip.
2. Open `index.html` in your browser (no server required).
3. Use demo logins:
   - Admin: `admin / 123456`
   - CSO: `cso1 / 123456`
   - Supir: `driver1 / 123456`, `driver2 / 123456`

> **Note:** For production, replace localStorage with a real backend (API + database), add real authentication, server-side authorization checks, and use a build step for Tailwind.

## Project Structure

```
KoperasiTaksiPOS/
├── index.html                   # Login
├── pages/
│   ├── admin.html               # Admin dashboard (hash-routed sections)
│   ├── cso.html                 # CSO one-page flow
│   └── driver.html              # Driver mobile-first dashboard
├── assets/
│   ├── img/
│   │   └── qris-placeholder.svg
│   ├── js/
│   │   ├── auth.js
│   │   ├── data.js
│   │   ├── utils.js
│   │   ├── admin.js
│   │   ├── cso.js
│   │   └── driver.js
│   └── css/                     # (empty; Tailwind via CDN)
└── README.md
```

## Feature Notes

- **Zona & Tarif**: Admin can CRUD zones and set fixed prices per (asal → tujuan) pair.
- **Users (CSO/Driver/Admin)**: Add/edit/activate/deactivate; drivers have vehicle & plate fields.
- **CSO Booking & Payment**: Select zones → auto-load tariff → assign available driver → payment (QRIS / Cash to CSO / Cash to Driver). Records a transaction.
- **Driver Dashboard**:
  - **Order Aktif & Notifikasi**: Active order banner with slight pulsing effect.
  - **Dompet**: Balance = (QRIS + Cash to CSO) × (1 − komisi) − Approved/Paid withdrawals.
  - **Withdrawal**: Request and view status; Admin can Approve/Reject/Mark Paid.
  - **Riwayat Perjalanan**: Filter by date.
- **Admin Reports**: Weekly & monthly revenue charts (Chart.js), driver performance ranking by trips or revenue.
- **Role Routing**: Single login page redirects to role dashboard. Each dashboard enforces role guard.

## Security (Demo Caveats)

This is a front-end demo with mock data in localStorage—**not secure** for production use.  
For a real deployment:
- Implement a backend with JWT/session auth.
- Enforce server-side authorization per role.
- Use a database and transactional logic for payments & withdrawals.
- Replace static QR with real QRIS integration (e.g., acquirer API).

## Customization

- Commission rate: edit `commissionRate` in `assets/js/data.js` seed.
- Bank/QR image: replace `assets/img/qris-placeholder.svg` and the `bank` entry in seed.
