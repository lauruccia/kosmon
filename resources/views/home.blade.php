<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>KMoney &mdash; La Moneta Complementare del Gruppo Kosmos</title>
  <meta name="description" content="KMoney è la moneta complementare del Gruppo Kosmos. Apri il tuo conto, ottieni crediti KY e scambia beni e servizi all'interno del circuito Kosmos.">
  <meta property="og:title" content="KMoney &mdash; Circuito Kosmos">
  <meta property="og:description" content="La moneta complementare del Gruppo Kosmos. Preserva la liquidità, cresci nel circuito.">
  <meta property="og:type" content="website">
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet" />
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --bg:#0c1518;--bg-2:#111f25;--bg-3:#172830;
      --slate:#3d5566;--slate-2:#4d6878;--slate-3:#6a8898;
      --green:#4d7a52;--green-2:#6a9a6f;--green-3:#9abf9e;
      --white:#ffffff;--gray-1:#eef2f4;--gray-2:#c8d4d8;--gray-3:#7a9098;
      --glass:rgba(255,255,255,0.055);--glass-border:rgba(255,255,255,0.10);
      --shadow:0 24px 64px rgba(0,0,0,0.35);
      --grad:linear-gradient(135deg,var(--slate) 0%,var(--green) 100%);
      --grad-light:linear-gradient(135deg,var(--slate-3) 0%,var(--green-2) 100%);
    }
    html{scroll-behavior:smooth}
    body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--white);overflow-x:hidden;line-height:1.6}
    a{text-decoration:none;color:inherit}
    img{max-width:100%;display:block}
    ::-webkit-scrollbar{width:5px}
    ::-webkit-scrollbar-track{background:var(--bg)}
    ::-webkit-scrollbar-thumb{background:var(--green);border-radius:3px}
    .container{width:100%;padding:0 clamp(20px,5vw,80px)}
    .badge{display:inline-flex;align-items:center;gap:8px;background:rgba(77,122,82,0.15);border:1px solid rgba(77,122,82,0.3);color:var(--green-2);border-radius:100px;font-size:11px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;padding:6px 16px;margin-bottom:20px}
    .badge::before{content:'';width:6px;height:6px;background:var(--grad);border-radius:50%}
    .section-title{font-family:'Playfair Display',serif;font-size:clamp(32px,5vw,52px);font-weight:800;line-height:1.15;letter-spacing:-1px}
    .highlight{background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .btn-primary{display:inline-flex;align-items:center;gap:10px;background:var(--grad);color:var(--white);font-weight:700;font-size:15px;border:none;border-radius:50px;padding:15px 32px;cursor:pointer;transition:all .3s ease;letter-spacing:.3px;box-shadow:0 8px 24px rgba(61,85,102,0.35)}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 14px 36px rgba(77,122,82,0.45);filter:brightness(1.1)}
    .btn-outline{display:inline-flex;align-items:center;gap:10px;background:transparent;color:var(--white);font-weight:600;font-size:15px;border:1px solid var(--glass-border);border-radius:50px;padding:14px 30px;cursor:pointer;transition:all .3s ease;backdrop-filter:blur(8px)}
    .btn-outline:hover{background:var(--glass);border-color:rgba(106,152,110,0.4);transform:translateY(-2px)}
    /* NAVBAR */
    nav{position:fixed;top:0;left:0;right:0;z-index:100;padding:18px 0;transition:all .4s ease}
    nav.scrolled{background:rgba(12,21,24,0.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--glass-border);padding:12px 0}
    .nav-inner{display:flex;align-items:center;justify-content:space-between}
    .nav-logo{display:flex;align-items:center;gap:12px;font-family:'Playfair Display',serif;font-size:22px;font-weight:800}
    .nav-logo svg,.nav-logo img{width:34px;height:34px;object-fit:contain}
    .nav-links{display:flex;align-items:center;gap:36px;list-style:none}
    .nav-links a{font-size:14px;font-weight:500;color:rgba(255,255,255,0.7);transition:color .2s}
    .nav-links a:hover{color:var(--green-2)}
    .nav-actions{display:flex;align-items:center;gap:12px}
    .nav-login{font-size:14px;font-weight:600;color:rgba(255,255,255,0.8);padding:10px 20px;border-radius:50px;border:1px solid var(--glass-border);transition:all .2s}
    .nav-login:hover{background:var(--glass)}
    .nav-register{font-size:14px;font-weight:700;color:var(--white);background:var(--grad);padding:10px 22px;border-radius:50px;transition:all .2s;box-shadow:0 4px 16px rgba(77,122,82,0.3)}
    .nav-register:hover{filter:brightness(1.12);transform:translateY(-1px)}
    /* HERO */
    .hero{min-height:100vh;display:flex;align-items:center;position:relative;overflow:hidden;background:radial-gradient(ellipse 70% 55% at 20% 20%,rgba(61,85,102,0.22) 0%,transparent 60%),radial-gradient(ellipse 50% 40% at 80% 70%,rgba(77,122,82,0.15) 0%,transparent 55%),var(--bg)}
    .hero::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.025) 1px,transparent 1px);background-size:48px 48px}
    .orb{position:absolute;border-radius:50%;filter:blur(90px);pointer-events:none}
    .orb-1{width:520px;height:520px;background:rgba(61,85,102,0.22);top:-120px;left:-100px;animation:floatOrb 14s ease-in-out infinite}
    .orb-2{width:380px;height:380px;background:rgba(77,122,82,0.18);bottom:5%;right:0%;animation:floatOrb 18s ease-in-out infinite reverse}
    .orb-3{width:260px;height:260px;background:rgba(61,85,102,0.12);top:35%;right:18%;animation:floatOrb 11s ease-in-out infinite 3s}
    @keyframes floatOrb{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,-40px) scale(1.06)}}
    .hero-content{position:relative;z-index:2;display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:64px;padding:120px 0 80px}
    .hero-title{font-family:'Playfair Display',serif;font-size:clamp(40px,6vw,70px);font-weight:800;line-height:1.1;letter-spacing:-2px;margin-bottom:24px}
    .hero-title .line-brand{background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .hero-desc{font-size:18px;color:rgba(255,255,255,0.6);line-height:1.75;max-width:460px;margin-bottom:40px}
    .hero-actions{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:52px}
    .hero-stats{display:flex;gap:32px;padding-top:32px;border-top:1px solid var(--glass-border)}
    .hero-stat-num{font-family:'Playfair Display',serif;font-size:28px;font-weight:800;background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .hero-stat-label{font-size:12px;color:var(--gray-3);font-weight:500;margin-top:2px}
    /* CARD STACK */
    .hero-visual{position:relative;display:flex;justify-content:center;align-items:center}
    .card-stack{position:relative;width:380px;height:480px}
    .bank-card{position:absolute;width:355px;height:215px;border-radius:20px;display:flex;flex-direction:column;justify-content:space-between}
    .bank-card-main{background:radial-gradient(ellipse 55% 55% at 72% 38%,rgba(80,110,200,0.18) 0%,transparent 60%),radial-gradient(ellipse 35% 40% at 30% 70%,rgba(30,50,120,0.25) 0%,transparent 50%),linear-gradient(160deg,#1c2f72 0%,#182460 40%,#162258 70%,#1c2c6a 100%);border:1px solid rgba(100,140,230,0.18);box-shadow:0 32px 80px rgba(0,0,0,0.55),0 0 0 1px rgba(255,255,255,0.05),inset 0 1px 0 rgba(255,255,255,0.08);top:0;left:8px;z-index:3;overflow:hidden;padding:0;animation:floatCard 7s ease-in-out infinite}
    .bank-card-back-1{background:linear-gradient(150deg,#243880 0%,#1e2e65 100%);top:28px;left:-12px;z-index:2;opacity:.6;animation:floatCard 7s ease-in-out infinite 1.2s}
    .bank-card-back-2{background:linear-gradient(150deg,#1a2a60 0%,#151f4a 100%);top:56px;left:28px;z-index:1;opacity:.35;animation:floatCard 7s ease-in-out infinite 2.4s}
    @keyframes floatCard{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-14px) rotate(-4deg)}}
    .card-inner{position:relative;z-index:2;width:100%;height:100%;padding:18px 22px 16px;display:flex;flex-direction:column;justify-content:space-between}
    .card-galaxy{position:absolute;top:-10px;right:-10px;width:200px;height:175px;pointer-events:none;z-index:1;opacity:.9}
    .card-brand-name{font-family:'Inter',sans-serif;font-size:18px;font-weight:800;color:#fff;letter-spacing:2px;line-height:1}
    .card-brand-sub{font-size:8.5px;font-weight:500;letter-spacing:2.5px;color:rgba(160,190,255,0.75);text-transform:uppercase;margin-top:2px}
    .card-chip-metal{width:40px;height:30px;border-radius:5px;background:linear-gradient(135deg,#d8d0b8 0%,#b8b090 30%,#e0d8c0 50%,#a8a080 70%,#c8c0a0 100%);position:relative;overflow:hidden}
    .card-chip-metal::after{content:'';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:14px;height:14px;border-radius:2px;border:1px solid rgba(0,0,0,0.2);background:linear-gradient(135deg,#c8c0a0,#a09870)}
    .card-number-row{font-family:'Inter',sans-serif;font-size:14.5px;font-weight:500;letter-spacing:2.5px;color:rgba(255,255,255,0.92);margin-top:2px}
    .card-bottom-row{display:flex;align-items:flex-end;justify-content:space-between}
    .card-valid-label{font-size:6px;font-weight:600;color:rgba(160,185,255,0.6);text-transform:uppercase;letter-spacing:0.5px;line-height:1.3}
    .card-valid-date{font-size:13px;font-weight:600;color:rgba(255,255,255,0.88);letter-spacing:1px}
    .card-cvv{font-size:13px;font-weight:600;color:rgba(255,255,255,0.88);letter-spacing:1px;margin-left:14px}
    .card-holder-name{font-size:9.5px;font-weight:600;color:rgba(220,230,255,0.85);letter-spacing:1.5px;text-transform:uppercase;margin-top:5px}
    .card-mc{display:flex;position:relative;width:34px;height:22px;flex-shrink:0}
    .mc-l,.mc-r{position:absolute;width:22px;height:22px;border-radius:50%;opacity:.75}
    .mc-l{left:0;background:rgba(90,120,200,0.7)}
    .mc-r{left:12px;background:rgba(110,150,220,0.6)}
    .bubble{position:absolute;background:rgba(12,21,24,0.75);backdrop-filter:blur(16px);border:1px solid var(--glass-border);border-radius:16px;padding:14px 18px;display:flex;align-items:center;gap:12px;white-space:nowrap}
    .bubble-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
    .bubble-label{font-size:11px;color:var(--gray-3)}
    .bubble-val{font-size:15px;font-weight:700}
    .bubble-1{top:20px;right:-28px;animation:floatBubble 5s ease-in-out infinite}
    .bubble-2{bottom:130px;left:-52px;animation:floatBubble 7s ease-in-out infinite 2s}
    .bubble-3{bottom:18px;right:0px;animation:floatBubble 6s ease-in-out infinite 1s}
    @keyframes floatBubble{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
    /* FEATURES */
    .features{padding:100px 0;background:linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%)}
    .features-header{text-align:center;margin-bottom:64px}
    .features-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
    .feat-card{background:var(--glass);border:1px solid var(--glass-border);border-radius:24px;padding:36px 28px;transition:all .35s ease;position:relative;overflow:hidden}
    .feat-card:hover{transform:translateY(-6px);border-color:rgba(77,122,82,0.35);box-shadow:0 20px 48px rgba(0,0,0,0.3)}
    .feat-header{display:flex;align-items:center;gap:16px;margin-bottom:18px}
    .feat-icon{width:52px;height:52px;border-radius:15px;flex-shrink:0;background:linear-gradient(135deg,rgba(61,85,102,0.3),rgba(77,122,82,0.12));border:1px solid rgba(106,152,110,0.2);display:flex;align-items:center;justify-content:center;font-size:24px}
    .feat-title{font-size:17px;font-weight:700;line-height:1.25}
    .feat-desc{font-size:14px;color:var(--gray-3);line-height:1.7}
    /* ABOUT */
    .about{padding:100px 0;background:var(--bg-2);position:relative;overflow:hidden}
    .about-inner{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center}
    .about-img-bg{width:100%;height:460px;border-radius:28px;background:linear-gradient(135deg,#172830,#1e3530);border:1px solid var(--glass-border);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
    .about-k svg,.about-k img{width:160px;height:160px;object-fit:contain;opacity:.9}
    .about-img-badge{position:absolute;bottom:-16px;right:-16px;background:var(--grad);border-radius:20px;padding:20px 24px;box-shadow:0 12px 32px rgba(77,122,82,0.4)}
    .about-img-badge-num{font-family:'Playfair Display',serif;font-size:32px;font-weight:800;color:var(--white)}
    .about-img-badge-text{font-size:12px;color:rgba(255,255,255,0.7);font-weight:600}
    .about-pillars{display:flex;flex-direction:column;gap:20px;margin-top:36px}
    .pillar{display:flex;gap:18px;align-items:flex-start;padding:22px;border-radius:18px;background:var(--glass);border:1px solid var(--glass-border);transition:all .3s}
    .pillar:hover{border-color:rgba(77,122,82,0.3);background:rgba(77,122,82,0.05)}
    .pillar-icon{width:44px;height:44px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,rgba(61,85,102,0.35),rgba(77,122,82,0.2));display:flex;align-items:center;justify-content:center;font-size:20px}
    .pillar-title{font-size:15px;font-weight:700;margin-bottom:6px}
    .pillar-desc{font-size:13px;color:var(--gray-3);line-height:1.65}
    /* HOW */
    .how{padding:100px 0;background:linear-gradient(180deg,var(--bg-2) 0%,var(--bg) 100%)}
    .how-header{text-align:center;margin-bottom:72px}
    .how-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:32px;position:relative}
    .how-steps::before{content:'';position:absolute;top:40px;left:16%;right:16%;height:1px;background:linear-gradient(90deg,transparent,var(--slate-3),var(--green-2),transparent)}
    .how-step{text-align:center;padding:40px 24px;background:var(--glass);border:1px solid var(--glass-border);border-radius:24px;position:relative;transition:all .35s}
    .how-step:hover{transform:translateY(-6px);border-color:rgba(77,122,82,0.3)}
    .step-num{width:64px;height:64px;background:var(--grad);border-radius:20px;margin:0 auto 24px;display:flex;align-items:center;justify-content:center;font-family:'Playfair Display',serif;font-size:26px;font-weight:800;color:var(--white);box-shadow:0 8px 24px rgba(77,122,82,0.35)}
    .step-title{font-size:18px;font-weight:700;margin-bottom:12px}
    .step-desc{font-size:14px;color:var(--gray-3);line-height:1.7}
    /* WHY */
    .why{padding:100px 0;background:var(--bg);position:relative;overflow:hidden}
    .why-inner{display:grid;grid-template-columns:5fr 7fr;gap:80px;align-items:center}
    .why-benefits{display:flex;flex-direction:column;gap:20px;margin-top:36px}
    .benefit{display:flex;align-items:flex-start;gap:16px;padding:22px;border-radius:18px;background:var(--glass);border:1px solid var(--glass-border);transition:all .3s}
    .benefit:hover{border-color:rgba(77,122,82,0.3);background:rgba(77,122,82,0.04)}
    .benefit-check{width:40px;height:40px;flex-shrink:0;background:var(--grad);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px}
    .benefit-title{font-size:15px;font-weight:700;margin-bottom:4px}
    .benefit-desc{font-size:13px;color:var(--gray-3);line-height:1.6}
    .why-dashboard{background:var(--glass);border:1px solid var(--glass-border);border-radius:28px;padding:32px;backdrop-filter:blur(20px)}
    .dash-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px}
    .dash-title{font-size:13px;color:var(--gray-3);font-weight:500}
    .dash-dots{display:flex;gap:6px}
    .dash-dot{width:10px;height:10px;border-radius:50%}
    .dash-balance-label{font-size:12px;color:var(--gray-3);margin-bottom:6px}
    .dash-balance-num{font-family:'Playfair Display',serif;font-size:40px;font-weight:800;background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .dash-balance-currency{font-size:14px;color:var(--gray-3);margin-top:4px;margin-bottom:28px}
    .dash-bar-row{display:flex;justify-content:space-between;font-size:12px;color:var(--gray-3);margin-bottom:8px}
    .dash-bar-track{height:8px;background:rgba(255,255,255,0.07);border-radius:99px;margin-bottom:18px}
    .dash-bar-fill{height:100%;border-radius:99px;background:var(--grad)}
    .dash-bar-fill-green{background:linear-gradient(90deg,#4ade80,#22c55e)}
    .dash-txs{margin-top:28px;display:flex;flex-direction:column;gap:12px}
    .dash-tx{display:flex;align-items:center;gap:14px;padding:14px;border-radius:14px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06)}
    .tx-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
    .tx-info{flex:1}
    .tx-name{font-size:13px;font-weight:600}
    .tx-date{font-size:11px;color:var(--gray-3)}
    .tx-amount{font-size:14px;font-weight:700}
    .tx-pos{color:#4ade80}
    .tx-neg{color:#f87171}
    /* STATS */
    .stats{padding:80px 0;background:linear-gradient(135deg,#111f25 0%,#172830 50%,#111f25 100%);border-top:1px solid var(--glass-border);border-bottom:1px solid var(--glass-border)}
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr)}
    .stat-item{text-align:center;padding:20px;border-right:1px solid var(--glass-border)}
    .stat-item:last-child{border-right:none}
    .stat-num{font-family:'Playfair Display',serif;font-size:52px;font-weight:800;background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1.1;margin-bottom:8px}
    .stat-label{font-size:14px;color:var(--gray-3);font-weight:500}
    /* FAQ */
    .faq{padding:100px 0;background:linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%)}
    .faq-header{text-align:center;margin-bottom:64px}
    .faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    .faq-item{background:var(--glass);border:1px solid var(--glass-border);border-radius:20px;padding:28px;cursor:pointer;transition:all .3s}
    .faq-item:hover{border-color:rgba(77,122,82,0.35);transform:translateY(-2px);background:rgba(77,122,82,0.04)}
    .faq-q{font-size:15px;font-weight:700;margin-bottom:12px;display:flex;align-items:flex-start;gap:12px}
    .faq-q::before{content:'Q';background:var(--grad);color:var(--white);font-size:10px;font-weight:800;width:22px;height:22px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;margin-top:1px}
    .faq-a{font-size:13px;color:var(--gray-3);line-height:1.7;padding-left:34px}
    /* CTA */
    .cta{padding:100px 0;background:var(--bg);position:relative;overflow:hidden}
    .cta::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 65% 55% at 50% 50%,rgba(61,85,102,0.14) 0%,transparent 70%)}
    .cta-inner{position:relative;background:linear-gradient(135deg,#172830 0%,#0c1d18 60%,#152a25 100%);border:1px solid var(--glass-border);border-radius:36px;padding:72px;display:flex;align-items:center;justify-content:space-between;gap:48px;overflow:hidden}
    .cta-title{font-family:'Playfair Display',serif;font-size:40px;font-weight:800;margin-bottom:16px;line-height:1.2}
    .cta-desc{font-size:16px;color:rgba(255,255,255,0.55);max-width:420px}
    .cta-actions{display:flex;gap:16px;flex-shrink:0}
    /* FOOTER */
    footer{background:#080f12;border-top:1px solid var(--glass-border);padding:72px 0 40px}
    .footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr;gap:48px;margin-bottom:56px}
    .footer-logo{display:flex;align-items:center;gap:12px;font-family:'Playfair Display',serif;font-size:20px;font-weight:800;margin-bottom:16px}
    .footer-logo svg,.footer-logo img{width:30px;height:30px;object-fit:contain}
    .footer-desc{font-size:13px;color:var(--gray-3);line-height:1.75;margin-bottom:24px}
    .footer-socials{display:flex;gap:10px}
    .social-btn{width:38px;height:38px;background:var(--glass);border:1px solid var(--glass-border);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;transition:all .2s}
    .social-btn:hover{background:rgba(77,122,82,0.15);border-color:rgba(77,122,82,0.35)}
    .footer-col-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--green-2);margin-bottom:20px}
    .footer-links{display:flex;flex-direction:column;gap:12px}
    .footer-links a{font-size:14px;color:var(--gray-3);transition:color .2s}
    .footer-links a:hover{color:var(--white)}
    .footer-contact-item{display:flex;gap:10px;align-items:flex-start;margin-bottom:14px;font-size:14px;color:var(--gray-3)}
    .footer-contact-icon{font-size:15px;flex-shrink:0;margin-top:1px}
    .newsletter-input-wrap{display:flex;margin-top:12px}
    .newsletter-input{flex:1;background:var(--glass);border:1px solid var(--glass-border);border-right:none;border-radius:12px 0 0 12px;padding:13px 16px;color:var(--white);font-size:14px;outline:none;font-family:'Inter',sans-serif}
    .newsletter-input::placeholder{color:var(--gray-3)}
    .newsletter-btn{background:var(--grad);border:none;border-radius:0 12px 12px 0;padding:13px 20px;cursor:pointer;color:var(--white);font-weight:700;font-size:14px;transition:filter .2s}
    .newsletter-btn:hover{filter:brightness(1.12)}
    .footer-bottom{padding-top:32px;border-top:1px solid var(--glass-border);display:flex;align-items:center;justify-content:space-between}
    .footer-copy{font-size:13px;color:var(--gray-3)}
    .footer-copy strong{background:var(--grad-light);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
    .footer-legal{display:flex;gap:24px}
    .footer-legal a{font-size:13px;color:var(--gray-3);transition:color .2s}
    .footer-legal a:hover{color:var(--white)}
    .reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
    .reveal.visible{opacity:1;transform:translateY(0)}
    @media(max-width:1024px){.features-grid{grid-template-columns:repeat(2,1fr)}.stats-grid{grid-template-columns:repeat(2,1fr)}.faq-grid{grid-template-columns:1fr}.footer-grid{grid-template-columns:1fr 1fr}.cta-inner{flex-direction:column;text-align:center;padding:48px 36px}.how-steps::before{display:none}}
    @media(max-width:768px){.nav-links{display:none}.hero-content{grid-template-columns:1fr;text-align:center}.hero-actions{justify-content:center}.hero-stats{justify-content:center}.hero-visual{display:none}.about-inner,.why-inner,.how-steps{grid-template-columns:1fr}.features-grid{grid-template-columns:1fr}.footer-grid{grid-template-columns:1fr}.footer-bottom{flex-direction:column;gap:16px;text-align:center}}
  </style>
</head>
<body>

@php
  $fmt = function(int $n, string $unit = '') use (&$fmt): string {
    if ($n >= 1000) return number_format($n / 1000, 0, ',', '.') . 'K+';
    if ($n > 0)     return $n . '+';
    return '0';
  };
@endphp

<svg width="0" height="0" style="position:absolute">
  <defs>
    <linearGradient id="kGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%"   stop-color="#3d5566"/>
      <stop offset="100%" stop-color="#4d7a52"/>
    </linearGradient>
  </defs>
</svg>

<nav id="navbar">
  <div class="container">
    <div class="nav-inner">
      <a href="{{ url('/') }}" class="nav-logo">
        <img src="{{ asset('assets/brand/kmoney-logo.png') }}" alt="KMoney">
        KMoney
      </a>
      <ul class="nav-links">
        <li><a href="#about">Chi Siamo</a></li>
        <li><a href="#services">Servizi</a></li>
        <li><a href="#how">Come Funziona</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="#contact">Contatti</a></li>
      </ul>
      <div class="nav-actions">
        <a href="{{ route('login') }}" class="nav-login">Accedi</a>
        <a href="{{ route('register') }}" class="nav-register">Registrati</a>
      </div>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
  <div class="container">
    <div class="hero-content">
      <div class="hero-text">
        <div class="badge">Moneta Complementare &middot; Kosmos Group</div>
        <h1 class="hero-title">
          Il futuro<br>del valore &egrave;<br>
          <span class="line-brand">KMoney</span>
        </h1>
        <p class="hero-desc">
          La moneta complementare trasversale del Gruppo Kosmos. Ogni giorno le aziende del circuito scambiano beni e servizi preservando la propria liquidit&agrave;.
        </p>
        <div class="hero-actions">
          <a href="{{ route('register') }}" class="btn-primary">
            Crea il tuo conto
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
          <a href="#how" class="btn-outline">Come funziona</a>
        </div>
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-num">{{ $stats['companies'] > 0 ? $stats['companies'].'+' : '—' }}</div>
            <div class="hero-stat-label">Aziende nel circuito</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-num">{{ $stats['transfers'] > 0 ? $fmt($stats['transfers']) : '—' }}</div>
            <div class="hero-stat-label">Transazioni totali</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-num">{{ $stats['listings'] > 0 ? $stats['listings'].'+' : '—' }}</div>
            <div class="hero-stat-label">Prodotti &amp; servizi</div>
          </div>
        </div>
      </div>

      <div class="hero-visual">
        <div class="card-stack">
          <div class="bank-card bank-card-back-2"></div>
          <div class="bank-card bank-card-back-1"></div>
          <div class="bank-card bank-card-main">
            <div class="card-galaxy">
              <svg viewBox="0 0 220 200" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%">
                <defs>
                  <radialGradient id="gc2" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#ffffff" stop-opacity="1"/><stop offset="18%" stop-color="#e8f2ff" stop-opacity=".95"/><stop offset="45%" stop-color="#88b4ff" stop-opacity=".55"/><stop offset="80%" stop-color="#3865d0" stop-opacity=".15"/><stop offset="100%" stop-color="#1835a0" stop-opacity="0"/></radialGradient>
                  <radialGradient id="gh2" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#5888e8" stop-opacity=".3"/><stop offset="55%" stop-color="#2848b8" stop-opacity=".08"/><stop offset="100%" stop-color="#102880" stop-opacity="0"/></radialGradient>
                  <radialGradient id="gd2" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#a0c0ff" stop-opacity=".38"/><stop offset="60%" stop-color="#6090e0" stop-opacity=".15"/><stop offset="100%" stop-color="#3060c0" stop-opacity="0"/></radialGradient>
                  <filter id="f1b"><feGaussianBlur stdDeviation="1.2"/></filter>
                  <filter id="f2b"><feGaussianBlur stdDeviation="2.8"/></filter>
                  <filter id="f5b"><feGaussianBlur stdDeviation="5"/></filter>
                  <filter id="f9b"><feGaussianBlur stdDeviation="9"/></filter>
                </defs>
                <ellipse cx="138" cy="72" rx="82" ry="52" fill="rgba(55,85,195,0.07)" filter="url(#f9b)" transform="rotate(-28 138 72)"/>
                <ellipse cx="138" cy="72" rx="66" ry="34" fill="url(#gd2)" filter="url(#f5b)" transform="rotate(-28 138 72)"/>
                <path d="M148,62 C162,46 178,30 192,20 C202,12 207,19 201,31 C193,46 178,57 164,64 C178,65 194,72 201,83 C207,93 202,104 190,106 C178,108 167,100 157,90" fill="rgba(115,162,255,0.15)" filter="url(#f5b)"/>
                <path d="M148,62 C163,46 178,30 192,20 C202,12 207,19 201,31 C193,46 178,57 164,64 C178,65 194,72 201,83 C207,93 202,104 190,106 C178,108 167,100 157,90" fill="none" stroke="rgba(155,198,255,0.38)" stroke-width="7" filter="url(#f2b)" stroke-linecap="round"/>
                <path d="M148,62 C163,46 178,31 190,21" fill="none" stroke="rgba(210,232,255,0.58)" stroke-width="2.5" filter="url(#f1b)" stroke-linecap="round"/>
                <path d="M128,82 C112,98 96,116 88,133 C82,146 88,157 100,159 C112,161 126,151 134,137 C127,151 123,165 130,173 C137,179 152,176 160,164 C168,152 163,136 154,124" fill="rgba(95,145,255,0.13)" filter="url(#f5b)"/>
                <path d="M128,82 C112,98 96,116 88,133 C82,146 88,157 100,159 C112,161 126,151 134,137 C127,151 123,165 130,173 C137,179 152,176 160,164 C168,152 163,136 154,124" fill="none" stroke="rgba(135,183,255,0.32)" stroke-width="7" filter="url(#f2b)" stroke-linecap="round"/>
                <ellipse cx="138" cy="72" rx="30" ry="13" fill="rgba(170,205,255,0.28)" filter="url(#f2b)" transform="rotate(-28 138 72)"/>
                <ellipse cx="138" cy="72" rx="16" ry="7" fill="rgba(210,228,255,0.42)" filter="url(#f1b)" transform="rotate(-28 138 72)"/>
                <circle cx="138" cy="72" r="26" fill="url(#gh2)" filter="url(#f9b)"/>
                <circle cx="138" cy="72" r="14" fill="rgba(170,208,255,0.62)" filter="url(#f5b)"/>
                <circle cx="138" cy="72" r="7"  fill="url(#gc2)" filter="url(#f1b)"/>
                <circle cx="138" cy="72" r="2.8" fill="#ffffff"/>
                <path d="M170,24 L171.4,18 L172.8,24 L179,25.4 L172.8,26.8 L171.4,33 L170,26.8 L163.8,25.4 Z" fill="#ffffff" opacity=".95"/>
                <circle cx="178" cy="17" r="1.4" fill="#ffffff" opacity=".96"/>
                <circle cx="161" cy="12" r="1.1" fill="#ffffff" opacity=".88"/>
                <circle cx="196" cy="32" r="1.0" fill="#c8dcff" opacity=".82"/>
                <circle cx="187" cy="50" r="0.9" fill="#ffffff" opacity=".78"/>
                <circle cx="150" cy="18" r="1.0" fill="#e8f2ff" opacity=".82"/>
                <circle cx="208" cy="58" r="0.9" fill="#ffffff" opacity=".68"/>
                <circle cx="143" cy="8"  r="0.8" fill="#ffffff" opacity=".72"/>
              </svg>
            </div>
            <div class="card-inner">
              <div style="display:flex;align-items:flex-start;justify-content:space-between">
                <div>
                  <div class="card-brand-name">KOSMOS</div>
                  <div class="card-brand-sub">Gruppo &middot; Circuito KY</div>
                </div>
              </div>
              <div>
                <div class="card-chip-metal"></div>
                <div class="card-number-row" style="margin-top:10px">KY&bull;&bull;&nbsp;&nbsp;&bull;&bull;&bull;&bull;&nbsp;&nbsp;&bull;&bull;&bull;&bull;&nbsp;&nbsp;&bull;&bull;&bull;&bull;</div>
              </div>
              <div>
                <div class="card-bottom-row">
                  <div>
                    <div style="display:flex;align-items:baseline;gap:6px">
                      <div class="card-valid-label">VALID<br>THRU</div>
                      <div class="card-valid-date">10/28</div>
                      <div class="card-cvv">KY</div>
                    </div>
                    <div class="card-holder-name">TITOLARE DEL CONTO</div>
                  </div>
                  <div class="card-mc"><div class="mc-l"></div><div class="mc-r"></div></div>
                </div>
              </div>
            </div>
          </div>
          <div class="bubble bubble-1">
            <div class="bubble-icon" style="background:rgba(74,222,128,0.12)">💸</div>
            <div>
              <div class="bubble-label">Inviato</div>
              <div class="bubble-val" style="color:#4ade80">+ 450 KY</div>
            </div>
          </div>
          <div class="bubble bubble-2">
            <div class="bubble-icon" style="background:rgba(61,85,102,0.25)">🔒</div>
            <div>
              <div class="bubble-label">OTP Verificato</div>
              <div class="bubble-val">Sicuro ✓</div>
            </div>
          </div>
          <div class="bubble bubble-3">
            <div class="bubble-icon" style="background:rgba(77,122,82,0.2)">⚡</div>
            <div>
              <div class="bubble-label">Cash Back</div>
              <div class="bubble-val" style="color:var(--green-2)">Attivo</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="features" id="services">
  <div class="container">
    <div class="features-header reveal">
      <div class="badge">I nostri servizi</div>
      <h2 class="section-title">Tutto ci&ograve; di cui hai <span class="highlight">bisogno</span></h2>
    </div>
    <div class="features-grid">
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">🏦</div><div class="feat-title">Conto KY</div></div>
        <div class="feat-desc">Apri e gestisci il tuo conto KMoney. Accedi ai tuoi crediti KY ovunque ti trovi, in modo semplice e veloce.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">💳</div><div class="feat-title">Linea di Credito</div></div>
        <div class="feat-desc">Richied la linea di credito (fido) pi&ugrave; adatta a te direttamente dal portale. Verifica i requisiti e inizia subito.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">🎁</div><div class="feat-title">Cash Back &amp; Promozioni</div></div>
        <div class="feat-desc">Ottieni cash back su ogni acquisto e accedi alle promozioni riservate alle aziende del circuito Kosmos.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">⚡</div><div class="feat-title">Pagamenti Istantanei</div></div>
        <div class="feat-desc">Trasferisci KY a qualsiasi azienda del circuito. Istantaneo, sicuro, senza commissioni nascoste.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">📱</div><div class="feat-title">QR &amp; NFC</div></div>
        <div class="feat-desc">Incassa con un QR dinamico o tramite NFC. Il cliente paga in un tap dal proprio smartphone.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">📊</div><div class="feat-title">Estratto Conto PDF</div></div>
        <div class="feat-desc">Esporta il tuo estratto conto in PDF o CSV in qualsiasi momento. Ideale per la contabilit&agrave; aziendale.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">🔄</div><div class="feat-title">Pagamenti Rateali</div></div>
        <div class="feat-desc">Offri o richiedi piani di pagamento rateale con approvazione bilaterale. Flessibilit&agrave; per fornitore e cliente.</div>
      </div>
      <div class="feat-card reveal">
        <div class="feat-header"><div class="feat-icon">🔗</div><div class="feat-title">API &amp; Webhook</div></div>
        <div class="feat-desc">Integra KMoney nel tuo gestionale tramite API REST v1 e webhook in tempo reale. Documentazione inclusa.</div>
      </div>
    </div>
  </div>
</section>

<section class="about" id="about">
  <div class="container">
    <div class="about-inner">
      <div class="about-image-wrap reveal">
        <div class="about-img-bg">
          <div class="about-k">
            <img src="{{ asset('assets/brand/kmoney-logo.png') }}" alt="KMoney">
          </div>
        </div>
        <div class="about-img-badge">
          <div class="about-img-badge-num">30+</div>
          <div class="about-img-badge-text">Anni di esperienza</div>
        </div>
      </div>
      <div class="about-text reveal">
        <div class="badge">Chi siamo</div>
        <h2 class="section-title">Un servizio <span class="highlight">facile, sicuro</span> e trasparente</h2>
        <div class="about-pillars">
          <div class="pillar">
            <div class="pillar-icon">🎯</div>
            <div>
              <div class="pillar-title">La nostra missione</div>
              <div class="pillar-desc">Costruiamo e manteniamo relazioni generazionali a lungo termine con i nostri clienti, mettendo al centro la crescita del business.</div>
            </div>
          </div>
          <div class="pillar">
            <div class="pillar-icon">🔭</div>
            <div>
              <div class="pillar-title">La nostra visione</div>
              <div class="pillar-desc">KMoney diventer&agrave; la moneta complementare pi&ugrave; diffusa in Italia, creando un ecosistema di scambio virtuoso tra PMI.</div>
            </div>
          </div>
          <div class="pillar">
            <div class="pillar-icon">🚀</div>
            <div>
              <div class="pillar-title">Il nostro obiettivo</div>
              <div class="pillar-desc">Aiutare ogni azienda del circuito a preservare la propria liquidit&agrave; in euro, crescere grazie agli scambi KY e accedere a nuovi clienti.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="how" id="how">
  <div class="container">
    <div class="how-header reveal">
      <div class="badge">Come funziona</div>
      <h2 class="section-title">Inizia in <span class="highlight">3 semplici passi</span></h2>
    </div>
    <div class="how-steps">
      <div class="how-step reveal">
        <div class="step-num">1</div>
        <div class="step-title">Apri un conto</div>
        <div class="step-desc">Registra la tua azienda gratuitamente. La procedura &egrave; online, richiede pochi minuti e nessun costo di attivazione.</div>
      </div>
      <div class="how-step reveal">
        <div class="step-num">2</div>
        <div class="step-title">Completa il KYC</div>
        <div class="step-desc">Carica i documenti aziendali per la verifica KYC. Una volta approvato, il tuo conto KY sar&agrave; pienamente operativo.</div>
      </div>
      <div class="how-step reveal">
        <div class="step-num">3</div>
        <div class="step-title">Inizia a scambiare</div>
        <div class="step-desc">Pubblica i tuoi prodotti e servizi, trova fornitori nel circuito e inizia a transare in KY preservando i tuoi euro.</div>
      </div>
    </div>
  </div>
</section>

<section class="why">
  <div class="container">
    <div class="why-inner">
      <div class="reveal">
        <div class="badge">Perch&eacute; KMoney</div>
        <h2 class="section-title">I migliori <span class="highlight">vantaggi</span> per te</h2>
        <div class="why-benefits">
          <div class="benefit">
            <div class="benefit-check">💰</div>
            <div>
              <div class="benefit-title">Preserva la liquidit&agrave;</div>
              <div class="benefit-desc">Paga fornitori in KY invece di euro: meno uscite di cassa, pi&ugrave; flusso di liquidit&agrave; per il tuo business.</div>
            </div>
          </div>
          <div class="benefit">
            <div class="benefit-check">🤝</div>
            <div>
              <div class="benefit-title">Rete di clienti qualificati</div>
              <div class="benefit-desc">Accedi a un network B2B selezionato di aziende e professionisti che cercano attivamente nuovi fornitori.</div>
            </div>
          </div>
          <div class="benefit">
            <div class="benefit-check">🛡️</div>
            <div>
              <div class="benefit-title">Sicuro e tracciabile</div>
              <div class="benefit-desc">Ogni transazione &egrave; registrata su ledger contabile. 2FA, audit log e KYC obbligatorio garantiscono la massima sicurezza.</div>
            </div>
          </div>
          <div class="benefit">
            <div class="benefit-check">📈</div>
            <div>
              <div class="benefit-title">Strumenti business avanzati</div>
              <div class="benefit-desc">API REST, webhook, rate rateali, netting crediti incrociati: tutto ci&ograve; che serve per integrare KMoney nel tuo flusso operativo.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="reveal">
        <div class="why-dashboard">
          <div class="dash-header">
            <div class="dash-title">Il tuo portale KMoney</div>
            <div class="dash-dots">
              <div class="dash-dot" style="background:#f87171"></div>
              <div class="dash-dot" style="background:#fbbf24"></div>
              <div class="dash-dot" style="background:#4ade80"></div>
            </div>
          </div>
          <div class="dash-balance-label">Saldo disponibile</div>
          <div class="dash-balance-num">12.840</div>
          <div class="dash-balance-currency">KY &mdash; Crediti Kosmos</div>
          <div class="dash-bar-row"><span>Fido utilizzato</span><span>6.400 / 15.000 KY</span></div>
          <div class="dash-bar-track"><div class="dash-bar-fill" style="width:43%"></div></div>
          <div class="dash-bar-row"><span>Capacit&agrave; commerciale</span><span>100%</span></div>
          <div class="dash-bar-track"><div class="dash-bar-fill dash-bar-fill-green" style="width:100%"></div></div>
          <div class="dash-txs">
            <div class="dash-tx">
              <div class="tx-icon" style="background:rgba(74,222,128,0.1)">📥</div>
              <div class="tx-info"><div class="tx-name">Azienda Esempio Srl</div><div class="tx-date">Oggi, 14:32</div></div>
              <div class="tx-amount tx-pos">+1.200 KY</div>
            </div>
            <div class="dash-tx">
              <div class="tx-icon" style="background:rgba(248,113,113,0.1)">📤</div>
              <div class="tx-info"><div class="tx-name">Fornitore Beta</div><div class="tx-date">Ieri, 09:15</div></div>
              <div class="tx-amount tx-neg">-450 KY</div>
            </div>
            <div class="dash-tx">
              <div class="tx-icon" style="background:rgba(251,191,36,0.1)">🔄</div>
              <div class="tx-info"><div class="tx-name">Rimborso automatico</div><div class="tx-date">2 gg fa</div></div>
              <div class="tx-amount tx-pos">+85 KY</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="stats">
  <div class="container">
    <div class="stats-grid">
      <div class="stat-item reveal">
        <div class="stat-num">{{ $stats['companies'] > 0 ? $stats['companies'].'+' : '—' }}</div>
        <div class="stat-label">Aziende nel circuito</div>
      </div>
      <div class="stat-item reveal">
        <div class="stat-num">{{ $stats['transfers'] > 0 ? $fmt($stats['transfers']) : '—' }}</div>
        <div class="stat-label">Transazioni registrate</div>
      </div>
      <div class="stat-item reveal">
        <div class="stat-num">{{ $stats['listings'] > 0 ? $stats['listings'].'+' : '—' }}</div>
        <div class="stat-label">Prodotti &amp; Servizi</div>
      </div>
      <div class="stat-item reveal">
        <div class="stat-num">30+</div>
        <div class="stat-label">Anni Kosmos Group</div>
      </div>
    </div>
  </div>
</section>

<section class="faq" id="faq">
  <div class="container">
    <div class="faq-header reveal">
      <div class="badge">Domande frequenti</div>
      <h2 class="section-title">Hai delle <span class="highlight">domande?</span></h2>
    </div>
    <div class="faq-grid">
      <div class="faq-item reveal">
        <div class="faq-q">Aprire il conto &egrave; gratis?</div>
        <div class="faq-a">S&igrave;, puoi aprire il tuo conto KMoney completamente gratuitamente in pochi minuti. La registrazione non prevede costi di attivazione.</div>
      </div>
      <div class="faq-item reveal">
        <div class="faq-q">Posso usare i KY fuori dal circuito?</div>
        <div class="faq-a">No, i KY sono una valuta complementare e possono essere utilizzati esclusivamente all'interno del circuito Kosmos tra le aziende aderenti.</div>
      </div>
      <div class="faq-item reveal">
        <div class="faq-q">Come posso aprire un conto?</div>
        <div class="faq-a">Clicca su "Registrati", compila i dati della tua azienda e carica i documenti richiesti per la verifica KYC. L'approvazione avviene entro 24-48h lavorative.</div>
      </div>
      <div class="faq-item reveal">
        <div class="faq-q">KMoney condivide le mie informazioni?</div>
        <div class="faq-a">No. I tuoi dati non vengono ceduti a terzi. Operiamo nel pieno rispetto del GDPR e della normativa sulla privacy italiana.</div>
      </div>
      <div class="faq-item reveal">
        <div class="faq-q">Come richiedere una linea di credito?</div>
        <div class="faq-a">Dal tuo portale, nella sezione "Fido", puoi visualizzare i requisiti e inviare una richiesta. L'admin del circuito la valuter&agrave; in base alla tua attivit&agrave;.</div>
      </div>
      <div class="faq-item reveal">
        <div class="faq-q">Esiste un'API per integrare KMoney?</div>
        <div class="faq-a">S&igrave;. Offriamo una API REST v1 con autenticazione Bearer token, endpoint per trasferimenti e saldo, pi&ugrave; webhook per notifiche real-time.</div>
      </div>
    </div>
  </div>
</section>

<section class="cta">
  <div class="container">
    <div class="cta-inner reveal">
      <div class="cta-text">
        <h2 class="cta-title">Entra nel circuito<br><span class="highlight">KMoney oggi</span></h2>
        <p class="cta-desc">Unisciti alle aziende che gi&agrave; scambiano valore nel circuito Kosmos, preservando la liquidit&agrave; e crescendo insieme.</p>
      </div>
      <div class="cta-actions">
        <a href="{{ route('register') }}" class="btn-primary">
          Crea un account gratuito
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <a href="mailto:info@kosmomoney.com" class="btn-outline">Contattaci</a>
      </div>
    </div>
  </div>
</section>

<footer id="contact">
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-logo">
          <img src="{{ asset('assets/brand/kmoney-logo.png') }}" alt="KMoney">
          KMoney
        </div>
        <p class="footer-desc">Kosmos Group opera nel settore del marketing e della comunicazione da oltre 30 anni. La nostra mission &egrave; accrescere la competitivit&agrave; di aziende e imprese attraverso soluzioni innovative come KMoney.</p>
        <div class="footer-socials">
          <a href="https://www.linkedin.com/" class="social-btn" target="_blank" rel="noopener">💼</a>
          <a href="https://www.instagram.com/" class="social-btn" target="_blank" rel="noopener">📸</a>
          <a href="https://twitter.com/" class="social-btn" target="_blank" rel="noopener">𝕏</a>
          <a href="https://www.facebook.com/" class="social-btn" target="_blank" rel="noopener">📘</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Navigazione</div>
        <div class="footer-links">
          <a href="#about">Chi Siamo</a>
          <a href="#services">Servizi</a>
          <a href="#how">Come Funziona</a>
          <a href="#faq">FAQ</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Account</div>
        <div class="footer-links">
          <a href="{{ route('register') }}">Registrati</a>
          <a href="{{ route('login') }}">Accedi</a>
          <a href="{{ url('/privacy') }}">Privacy Policy</a>
          <a href="{{ url('/termini') }}">Termini di servizio</a>
          <a href="{{ route('help.index') }}">Centro Assistenza</a>
          <a href="{{ route('legal.contract') }}">Contratto Adesione</a>
          <a href="{{ route('legal.aml-kyc') }}">AML/KYC</a>
          <a href="{{ route('legal.complaints') }}">Reclami</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Newsletter</div>
        <p style="font-size:13px;color:var(--gray-3)">Rimani aggiornato sulle novit&agrave; del circuito.</p>
        <div class="newsletter-input-wrap">
          <input type="email" class="newsletter-input" placeholder="La tua email" />
          <button class="newsletter-btn">&#8594;</button>
        </div>
        <div style="margin-top:28px">
          <div class="footer-col-title">Contatti</div>
          <div class="footer-contact-item"><span class="footer-contact-icon">📍</span>Via Eurialo 56, Roma</div>
          <div class="footer-contact-item"><span class="footer-contact-icon">✉️</span>info@kosmomoney.com</div>
          <div class="footer-contact-item"><span class="footer-contact-icon">📞</span>+39 377 571 3313</div>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="footer-copy">&copy; {{ date('Y') }} <strong>KNM S.R.L.</strong> &mdash; P.IVA: 13273091002 &mdash; Tutti i diritti riservati</div>
      <div class="footer-legal">
        <a href="{{ url('/privacy') }}">Privacy</a>
        <a href="{{ url('/termini') }}">Termini</a>
        <a href="{{ route('help.index') }}">Assistenza</a>
        <a href="#">Cookie</a>
      </div>
    </div>
  </div>
</footer>

<script>
  const navbar = document.getElementById('navbar');
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 40);
  });
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
      if (entry.isIntersecting) {
        setTimeout(() => entry.target.classList.add('visible'), i * 80);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  });
</script>
</body>
</html>
