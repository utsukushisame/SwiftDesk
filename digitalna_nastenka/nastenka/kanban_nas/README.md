# NAS Kanban Board (No-Database)

Robustná Single-File Kanban nástenka bežiaca na PHP bez databázy (JSON storage).

## Požiadavky
- **Web Server:** Apache alebo Nginx
- **PHP:** 7.4+
- **Browser:** Moderný prehliadač (Chrome, Firefox, Edge, Safari)

## Inštalácia na NAS (Synology / QNAP)

1. **Nahrajte súbory** do webového priečinka (napr. `/volume1/web/kanban` alebo `/share/Web/kanban`).
   - `api.php`
   - `index.html`

2. **Nastavenie Práv (Kľúčové!)**
   Aby skript mohol ukladať dáta a vytvárať zámky, musí mať PHP proces právo zápisu do priečinka.

   Prihláste sa cez SSH (alebo použite File Station manager) a spustite:

   ```bash
   # Prejdite do priečinka aplikácie
   cd /verzia/k/priečinku/kanban

   # Nastavte vlastníka na užívateľa web servera (zvyčajne 'http' alebo 'www-data')
   # Synology: http, QNAP: httpdusr
   chown -R http:http .  

   # Nastavte práva zápisu pre skupinu (aby ste mohli editovať aj vy cez SMB)
   chmod -R 775 .
   ```

   **Dôležité:** Priečinok `backups` sa vytvorí automaticky pri prvom zápise.

## Riešenie problémov

### "Connecting..." alebo "Offline"
- Skontrolujte, či beží PHP a či súbor `api.php` je dostupný cez webový prehliadač (`http://nas-ip/kanban/api.php?action=load`).
- Mal by vrátiť prázdne JSON pole `[]` alebo initial dáta.

### "Chyba pri ukladaní"
- Pravdepodobne zlé oprávnenia. Skontrolujte či PHP môže zapisovať do priečinka (viď index `chmod` vyššie).
- `api.php` potrebuje vytvárať: `data.json`, `data.lock`, `temp_*.json` a priečinok `backups/`.

### Konflikty
- Ak dvaja užívatelia upravia nástenku naraz, druhý dostane upozornenie a musí načítať novú verziu.

## Zálohovanie
Systém automaticky vytvára rotačné zálohy v priečinku `backups/`. Uchováva posledných 50 verzií. V prípade poškodenia `data.json` stačí premenovať poslednú zálohu na `data.json`.
