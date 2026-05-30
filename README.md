# Casa Nevia - MotoPress Custom Deposit Payment

Ovaj WordPress plugin dodaje funkcionalnost autorizacijskog pologa (Authorization Hold) za MotoPress Hotel Booking u kombinaciji sa Stripe sustavom za naplatu. 
Razvijen specifično kako bi riješio problem Stripeovog ograničenja od 7 dana za zadržavanje pologa.

## Kako funkcionira?

1. **Kreiranje rezervacije (Off-session setup)**
Kada gost napravi rezervaciju na web stranici, plugin dodaje `setup_future_usage: off_session` u Payment Intent. Stripe tada naplati iznos smještaja, ali i sigurno sačuva karticu (kreira Stripe Customer objekt).

2. **Noćno zamrzavanje (Hold)**
WP-Cron posao se vrti svakodnevno u 06:00. Pronalazi rezervacije čiji je check-in danas. Za te rezervacije kreira "Manual Capture" Payment Intent u iznosu od 400€. Sredstva se samo zamrznu na gostovoj kartici, bez skidanja.

3. **Naplata ili Otpuštanje pologa (Admin UI)**
Unutar WordPress administracije, na svakoj rezervaciji nalazi se Meta Box.
- **Sve je u redu - Otpusti polog (Zeleni gumb):** Oslobađa puni iznos pologa gostu.
- **Naplati štetu (Crveni gumb):** Trajno naplaćuje odabrani iznos od zamrznutih sredstava, a ostatak automatski vraća gostu.

4. **Sigurnosna mreža (Safety Net Release)**
Ako administrator zaboravi ručno otpustiti polog, WP-Cron posao koji se vrti svakodnevno u 08:00 automatski otpušta sva zamrznuta sredstva za rezervacije kod kojih je od check-outa prošlo točno 2 dana.

## Tehnologije
- PHP 8.4 OOP
- Stripe PHP SDK (ugrađen u MotoPress)
- Vanilla JavaScript i AJAX (WP Admin)
- WP-Cron

## Instalacija
Plugin se instalira kao standardni WordPress plugin. Ovisi o MotoPress Hotel Booking sustavu i njegovoj Stripe integraciji. Ne zahtijeva dodatne postavke osim postojanja Stripe ključeva unutar MotoPress postavki.
