<?php
include '../includes/config.php';
// Public welcome page - layout.php handles authentication
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Monitoring in One Place</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary:     #2D008A;
            --primary-mid: #1a0057;
            --accent:      #00d2ff;
            --accent-warm: #ff6b35;
            --surface:     #f5f4ff;
            --card-bg:     #ffffff;
            --text-main:   #0d0520;
            --text-muted:  #6b6880;
            --radius-lg:   20px;
            --radius-xl:   32px;
            --shadow-sm:   0 2px 12px rgba(45,0,138,.08);
            --shadow-md:   0 8px 32px rgba(45,0,138,.14);
            --shadow-lg:   0 20px 60px rgba(45,0,138,.18);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            color: var(--text-main);
            overflow-x: hidden;
        }

        /* ─── NAV ─── */
        .site-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,.85);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(45,0,138,.08);
            padding: 14px 0;
        }
        .nav-brand {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 1.5rem;
            color: var(--primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        .nav-brand span { color: var(--accent); }
        .nav-cta {
            background: var(--primary);
            color: #fff !important;
            border-radius: 10px;
            padding: 8px 22px !important;
            font-weight: 500;
            font-size: .875rem;
            transition: background .2s, transform .2s;
        }
        .nav-cta:hover { background: var(--primary-mid); transform: translateY(-1px); }

        /* ─── HERO ─── */
        .hero {
            position: relative;
            background: linear-gradient(135deg, var(--primary) 0%, #0041c2 100%);
            color: #fff;
            padding: 80px 0 100px;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 80% 20%, rgba(0,210,255,.18) 0%, transparent 70%),
                radial-gradient(ellipse 40% 60% at 10% 80%, rgba(255,107,53,.12) 0%, transparent 70%);
        }
        .hero-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .hero-content { position: relative; z-index: 2; }
        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 50px;
            padding: 6px 16px;
            font-size: .8rem;
            font-weight: 500;
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 24px;
            backdrop-filter: blur(8px);
        }
        .hero-eyebrow .dot {
            width: 7px; height: 7px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .5; transform: scale(1.4); }
        }
        .hero-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2.2rem, 6vw, 4rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -1px;
            margin-bottom: 20px;
        }
        .hero-title .accent { color: var(--accent); }
        .hero-sub {
            font-size: clamp(.95rem, 2.5vw, 1.15rem);
            font-weight: 300;
            opacity: .85;
            max-width: 520px;
            line-height: 1.7;
            margin-bottom: 36px;
        }
        .hero-actions { display: flex; gap: 14px; flex-wrap: wrap; }
        .btn-hero-primary {
            background: #fff;
            color: var(--primary);
            font-weight: 700;
            font-family: 'Syne', sans-serif;
            padding: 14px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-size: .95rem;
            transition: transform .2s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,.25); color: var(--primary); }
        .btn-hero-ghost {
            background: rgba(255,255,255,.12);
            color: #fff;
            font-weight: 500;
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-size: .95rem;
            border: 1px solid rgba(255,255,255,.25);
            backdrop-filter: blur(8px);
            transition: background .2s;
        }
        .btn-hero-ghost:hover { background: rgba(255,255,255,.2); color: #fff; }

        /* ─── FLOATING HERO CARD ─── */
        .hero-visual {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .hero-dashboard {
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: var(--radius-xl);
            padding: 24px;
            width: 100%;
            max-width: 380px;
            box-shadow: var(--shadow-lg);
            animation: float 5s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-12px); }
        }
        .dash-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .dash-dots { display: flex; gap: 6px; }
        .dash-dots span {
            width: 10px; height: 10px; border-radius: 50%;
        }
        .dash-dots span:nth-child(1) { background: #ff5f57; }
        .dash-dots span:nth-child(2) { background: #febc2e; }
        .dash-dots span:nth-child(3) { background: #28c840; }
        .dash-title { color: rgba(255,255,255,.6); font-size: .75rem; }
        .dash-metric {
            background: rgba(255,255,255,.08);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .dash-metric-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            flex-shrink: 0;
        }
        .dash-metric-icon.cyan  { background: rgba(0,210,255,.2); color: var(--accent); }
        .dash-metric-icon.warm  { background: rgba(255,107,53,.2); color: #ff8c5a; }
        .dash-metric-icon.green { background: rgba(40,200,100,.2); color: #4ddc8c; }
        .dash-metric-label { font-size: .72rem; color: rgba(255,255,255,.55); }
        .dash-metric-value { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 700; color: #fff; }
        .dash-bar-wrap { margin-top: 16px; }
        .dash-bar-label { display: flex; justify-content: space-between; color: rgba(255,255,255,.55); font-size: .7rem; margin-bottom: 6px; }
        .dash-bar { height: 6px; background: rgba(255,255,255,.12); border-radius: 4px; overflow: hidden; }
        .dash-bar-fill { height: 100%; border-radius: 4px; background: linear-gradient(90deg, var(--accent), #7b6fff); }

        /* ─── USER WELCOME ALERT ─── */
        .user-welcome {
            background: linear-gradient(90deg, #eef3ff 0%, #f5f4ff 100%);
            border-left: 4px solid var(--primary);
            border-radius: 12px;
            padding: 16px 20px;
            margin: 28px 0 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--primary);
            font-weight: 500;
        }

        /* ─── STATS STRIP ─── */
        .stats-strip {
            background: var(--primary);
            padding: 30px 0;
        }
        .stat-item { text-align: center; color: #fff; padding: 10px; }
        .stat-item .stat-val {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.6rem, 4vw, 2.4rem);
            font-weight: 800;
            color: var(--accent);
            line-height: 1;
            margin-bottom: 4px;
        }
        .stat-item .stat-label { font-size: .8rem; opacity: .75; }
        .stat-divider { width: 1px; background: rgba(255,255,255,.15); align-self: stretch; }

        /* ─── FEATURES ─── */
        .section-label {
            font-family: 'Syne', sans-serif;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--primary);
            margin-bottom: 12px;
        }
        .section-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.7rem, 4vw, 2.6rem);
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.5px;
            color: var(--text-main);
        }
        .section-title em { font-style: normal; color: var(--primary); }

        .feature-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 32px 28px;
            height: 100%;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(45,0,138,.06);
            transition: transform .3s ease, box-shadow .3s ease;
            position: relative;
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .3s ease;
        }
        .feature-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-md); }
        .feature-card:hover::before { transform: scaleX(1); }

        .feat-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 20px;
        }
        .feat-icon.purple { background: #ede8ff; color: var(--primary); }
        .feat-icon.cyan   { background: #e0f9ff; color: #0097b2; }
        .feat-icon.orange { background: #fff0ea; color: #c94f1a; }
        .feat-icon.green  { background: #e7f9ee; color: #1a7a3f; }
        .feat-icon.blue   { background: #e6eeff; color: #1a47b8; }
        .feat-icon.pink   { background: #ffeef5; color: #a0196b; }

        .feat-title {
            font-family: 'Syne', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-main);
        }
        .feat-desc { font-size: .875rem; color: var(--text-muted); line-height: 1.65; }

        /* ─── USE CASES ─── */
        .use-cases { background: #fff; padding: 80px 0; }
        .use-tab-btn {
            background: none;
            border: 1.5px solid rgba(45,0,138,.15);
            border-radius: 10px;
            padding: 10px 20px;
            font-family: 'DM Sans', sans-serif;
            font-size: .875rem;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            transition: all .2s;
            white-space: nowrap;
        }
        .use-tab-btn.active,
        .use-tab-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .use-case-content { display: none; }
        .use-case-content.active { display: block; }
        .use-case-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 36px;
            border: 1px solid rgba(45,0,138,.07);
        }
        .use-case-title {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 12px;
        }
        .use-case-desc { color: var(--text-muted); line-height: 1.7; margin-bottom: 24px; }
        .use-bullet {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
            font-size: .875rem;
            color: var(--text-main);
        }
        .use-bullet i { color: var(--primary); margin-top: 2px; flex-shrink: 0; }

        /* ─── CTA BANNER ─── */
        .cta-banner {
            background: linear-gradient(135deg, var(--primary) 0%, #0041c2 100%);
            border-radius: var(--radius-xl);
            padding: clamp(40px, 6vw, 70px) clamp(28px, 5vw, 60px);
            text-align: center;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .cta-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 50% 80% at 90% 50%, rgba(0,210,255,.15) 0%, transparent 70%);
        }
        .cta-banner > * { position: relative; z-index: 1; }
        .cta-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.6rem, 4vw, 2.5rem);
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 14px;
        }
        .cta-sub { opacity: .8; margin-bottom: 32px; font-size: .95rem; max-width: 480px; margin-left: auto; margin-right: auto; }
        .btn-cta {
            display: inline-block;
            background: #fff;
            color: var(--primary);
            font-weight: 700;
            font-family: 'Syne', sans-serif;
            padding: 14px 36px;
            border-radius: 12px;
            text-decoration: none;
            font-size: .95rem;
            transition: transform .2s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
        }
        .btn-cta:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,.25); color: var(--primary); }

        /* ─── FOOTER ─── */
        .site-footer {
            background: var(--text-main);
            color: rgba(255,255,255,.5);
            padding: 32px 0;
            font-size: .8rem;
            text-align: center;
        }
        .site-footer a { color: rgba(255,255,255,.4); text-decoration: none; }
        .site-footer a:hover { color: #fff; }
        .footer-brand {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 8px;
        }

        /* ─── MOBILE TWEAKS ─── */
        @media (max-width: 768px) {
            .hero { padding: 60px 0 70px; }
            .hero-visual { margin-top: 40px; }
            .hero-dashboard { max-width: 100%; }
            .stats-strip .d-flex { flex-wrap: wrap; gap: 0; }
            .stat-divider { display: none; }
            .stat-item { flex: 1 1 50%; border-bottom: 1px solid rgba(255,255,255,.08); padding: 16px 0; }
            .use-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .use-tabs::-webkit-scrollbar { display: none; }
            .use-case-card { padding: 24px; }
            .cta-banner { padding: 40px 24px; }
        }

        @media (max-width: 576px) {
            .hero-actions a { width: 100%; text-align: center; }
            .stat-item { flex: 1 1 100%; }
        }

        /* ─── FADE-IN ANIMATION ─── */
        .fade-up {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity .6s ease, transform .6s ease;
        }
        .fade-up.visible { opacity: 1; transform: translateY(0); }
    </style>
</head>
<body>

    <!-- ────── HERO ────── -->
    <section class="hero">
        <div class="hero-grid"></div>
        <div class="container hero-content">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <div class="hero-eyebrow">
                        <span class="dot"></span>
                        Now live — AI Workplan Generation
                    </div>
                    <h1 class="hero-title">
                        All your <span class="accent">program</span><br>monitoring in one place
                    </h1>
                    <p class="hero-sub">
                        Vuqa is the end-to-end digital platform for NGOs, government agencies, and development organisations to collect data, track outcomes, and generate AI-powered reports — paperlessly.
                    </p>
                    <div class="hero-actions">
                        <a href="register.php" class="btn-hero-primary">
                            <i class="fas fa-rocket me-2"></i>Start for free
                        </a>
                        <a href="#features" class="btn-hero-ghost">
                            Explore features <i class="fas fa-arrow-down ms-1"></i>
                        </a>
                    </div>

                    <?php if(isset($_SESSION['full_name'])): ?>
                    <div class="user-welcome mt-4">
                        <i class="fas fa-check-circle fa-lg"></i>
                        <span>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>! Pick up where you left off.</span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-6 hero-visual">
                    <div class="hero-dashboard">
                        <div class="dash-header">
                            <div class="dash-dots">
                                <span></span><span></span><span></span>
                            </div>
                            <span class="dash-title ms-2">Program Overview — Q3 2025</span>
                        </div>

                        <div class="dash-metric">
                            <div class="dash-metric-icon cyan"><i class="fas fa-users"></i></div>
                            <div>
                                <div class="dash-metric-label">Beneficiaries Reached</div>
                                <div class="dash-metric-value">12,480</div>
                            </div>
                        </div>
                        <div class="dash-metric">
                            <div class="dash-metric-icon warm"><i class="fas fa-clipboard-check"></i></div>
                            <div>
                                <div class="dash-metric-label">Activities Logged</div>
                                <div class="dash-metric-value">3,291</div>
                            </div>
                        </div>
                        <div class="dash-metric">
                            <div class="dash-metric-icon green"><i class="fas fa-robot"></i></div>
                            <div>
                                <div class="dash-metric-label">AI Reports Generated</div>
                                <div class="dash-metric-value">47</div>
                            </div>
                        </div>

                        <div class="dash-bar-wrap">
                            <div class="dash-bar-label">
                                <span>Target completion</span>
                                <span>78%</span>
                            </div>
                            <div class="dash-bar">
                                <div class="dash-bar-fill" style="width:78%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ────── STATS STRIP ────── -->
    <div class="stats-strip">
        <div class="container">
            <div class="d-flex align-items-center justify-content-around">
                <div class="stat-item">
                    <div class="stat-val">100%</div>
                    <div class="stat-label">Paperless Collection</div>
                </div>
                <div class="stat-divider d-none d-md-block"></div>
                <div class="stat-item">
                    <div class="stat-val">Real-time</div>
                    <div class="stat-label">Analytics & Reporting</div>
                </div>
                <div class="stat-divider d-none d-md-block"></div>
                <div class="stat-item">
                    <div class="stat-val">256-bit</div>
                    <div class="stat-label">Data Encryption</div>
                </div>
                <div class="stat-divider d-none d-md-block"></div>
                <div class="stat-item">
                    <div class="stat-val">AI</div>
                    <div class="stat-label">Workplan Generation</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ────── FEATURES ────── -->
    <section id="features" class="py-5 py-md-6" style="padding-top:80px !important; padding-bottom:80px !important;">
        <div class="container">
            <div class="text-center mb-5">
                <p class="section-label fade-up">What Vuqa does</p>
                <h2 class="section-title fade-up">Built for every stage of <em>program delivery</em></h2>
            </div>

            <div class="row g-4">
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon purple"><i class="fas fa-leaf"></i></div>
                        <div class="feat-title">Paperless Data Collection</div>
                        <p class="feat-desc">Replace clipboards with digital forms. Field teams submit data offline and sync when connected — zero paper, zero lost records.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon cyan"><i class="fas fa-chart-line"></i></div>
                        <div class="feat-title">Real-time Dashboards</div>
                        <p class="feat-desc">Live charts and indicator tracking give programme managers instant visibility into performance across all sites and teams.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon orange"><i class="fas fa-robot"></i></div>
                        <div class="feat-title">AI Workplan Generation</div>
                        <p class="feat-desc">Describe your programme goals and let Vuqa's AI draft a structured, timeline-ready workplan in seconds — ready for your team to refine.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon green"><i class="fas fa-lock"></i></div>
                        <div class="feat-title">Bank-grade Security</div>
                        <p class="feat-desc">All data is AES-256 encrypted at rest and in transit. Role-based access controls ensure each stakeholder sees only what they should.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon blue"><i class="fas fa-file-alt"></i></div>
                        <div class="feat-title">Automated Reporting</div>
                        <p class="feat-desc">Generate donor-ready, formatted reports in one click. Schedule recurring reports to be emailed automatically to stakeholders.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4 fade-up">
                    <div class="feature-card">
                        <div class="feat-icon pink"><i class="fas fa-users-cog"></i></div>
                        <div class="feat-title">Multi-programme Management</div>
                        <p class="feat-desc">Run multiple programmes, cohorts, and geographies from a single account. Aggregate or drill down with one click.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ────── USE CASES ────── -->
    <section class="use-cases">
        <div class="container">
            <div class="text-center mb-5">
                <p class="section-label fade-up">Who uses Vuqa</p>
                <h2 class="section-title fade-up">Built for <em>every type of programme</em></h2>
            </div>

            <!-- Tabs -->
            <div class="use-tabs d-flex gap-2 flex-nowrap justify-content-center mb-4 pb-2 overflow-auto">
                <button class="use-tab-btn active" onclick="showCase(this,'ngo')">NGO / INGO</button>
                <button class="use-tab-btn" onclick="showCase(this,'govt')">Government</button>
                <button class="use-tab-btn" onclick="showCase(this,'health')">Health Programmes</button>
                <button class="use-tab-btn" onclick="showCase(this,'education')">Education</button>
                <button class="use-tab-btn" onclick="showCase(this,'livelihoods')">Livelihoods</button>
            </div>

            <!-- Case: NGO -->
            <div id="case-ngo" class="use-case-content active fade-up">
                <div class="use-case-card">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-6">
                            <h3 class="use-case-title">NGO & INGO Programmes</h3>
                            <p class="use-case-desc">From project inception to donor closeout, Vuqa keeps your M&E data clean, auditable, and presentation-ready without a consultant's invoice.</p>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Log activities, outputs and outcomes daily</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Generate USAID, EU, or UN-formatted progress reports</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Map beneficiary coverage with built-in geo-tagging</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Manage multiple grants and reporting periods simultaneously</div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-center">
                            <div style="background:var(--surface); border-radius:16px; padding:28px; width:100%; max-width:300px; text-align:center;">
                                <i class="fas fa-hands-helping fa-3x mb-3" style="color:var(--primary);"></i>
                                <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--primary);">94%</div>
                                <div style="font-size:.8rem; color:var(--text-muted);">of NGO users say reporting time dropped by over half</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case: Government -->
            <div id="case-govt" class="use-case-content">
                <div class="use-case-card">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-6">
                            <h3 class="use-case-title">Government Programmes</h3>
                            <p class="use-case-desc">Centralise data from county offices, sub-counties, and field officers into one secure portal accessible to oversight bodies in real time.</p>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Hierarchical access for national, county & ward officers</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Audit trails and version history on all submissions</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Integration-ready APIs for existing government MIS</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Parliament-ready summary dashboards</div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-center">
                            <div style="background:var(--surface); border-radius:16px; padding:28px; width:100%; max-width:300px; text-align:center;">
                                <i class="fas fa-landmark fa-3x mb-3" style="color:var(--primary);"></i>
                                <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--primary);">47 Offices</div>
                                <div style="font-size:.8rem; color:var(--text-muted);">managed from a single Vuqa instance</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case: Health -->
            <div id="case-health" class="use-case-content">
                <div class="use-case-card">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-6">
                            <h3 class="use-case-title">Health Programmes</h3>
                            <p class="use-case-desc">Track patient cohorts, treatment outcomes, and community health worker performance — all while staying HIPAA-aligned and donor compliant.</p>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Cohort tracking with longitudinal follow-up</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> CHW performance scorecards</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Commodity and supply chain monitoring</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Interoperable with DHIS2 and ODK</div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-center">
                            <div style="background:var(--surface); border-radius:16px; padding:28px; width:100%; max-width:300px; text-align:center;">
                                <i class="fas fa-heartbeat fa-3x mb-3" style="color:var(--primary);"></i>
                                <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--primary);">50K+</div>
                                <div style="font-size:.8rem; color:var(--text-muted);">patient records managed across health programmes</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case: Education -->
            <div id="case-education" class="use-case-content">
                <div class="use-case-card">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-6">
                            <h3 class="use-case-title">Education Programmes</h3>
                            <p class="use-case-desc">Monitor enrolment, attendance, learning outcomes, and scholarship disbursements for school-based and community learning programmes.</p>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Learner enrolment and attendance registers</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Assessment score tracking and grade analytics</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Scholarship and bursary disbursement records</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Teacher and facilitator performance metrics</div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-center">
                            <div style="background:var(--surface); border-radius:16px; padding:28px; width:100%; max-width:300px; text-align:center;">
                                <i class="fas fa-graduation-cap fa-3x mb-3" style="color:var(--primary);"></i>
                                <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--primary);">200+ Schools</div>
                                <div style="font-size:.8rem; color:var(--text-muted);">tracked in education programme deployments</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case: Livelihoods -->
            <div id="case-livelihoods" class="use-case-content">
                <div class="use-case-card">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-6">
                            <h3 class="use-case-title">Livelihoods Programmes</h3>
                            <p class="use-case-desc">Track business grants, VSLA groups, vocational training, and income verification for economic empowerment and resilience programmes.</p>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> VSLA / savings group cycle tracking</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Startup grant disbursement and business mentoring logs</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Household income and asset verification</div>
                            <div class="use-bullet"><i class="fas fa-check-circle"></i> Market linkage and value chain monitoring</div>
                        </div>
                        <div class="col-md-6 d-flex justify-content-center">
                            <div style="background:var(--surface); border-radius:16px; padding:28px; width:100%; max-width:300px; text-align:center;">
                                <i class="fas fa-seedling fa-3x mb-3" style="color:var(--primary);"></i>
                                <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--primary);">8,000+</div>
                                <div style="font-size:.8rem; color:var(--text-muted);">beneficiary households tracked in livelihoods programmes</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ────── CTA ────── -->
    <section class="py-5 py-md-6" style="padding:60px 0;">
        <div class="container">
            <div class="cta-banner fade-up">
                <p class="cta-title">Ready to ditch the spreadsheets?</p>
                <p class="cta-sub">Join programme teams across East Africa already using Vuqa to monitor impact with confidence.</p>
                <a href="register.php" class="btn-cta">
                    <i class="fas fa-arrow-right me-2"></i>Get started — it's free
                </a>
            </div>
        </div>
    </section>

    <!-- ────── FOOTER ────── -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-brand">Vuqa</div>
            <p class="mb-2">Program monitoring in one place.</p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
                <a href="#">Support</a>
                <a href="#">Contact</a>
            </div>
            <p class="mt-3 mb-0">&copy; <?php echo date('Y'); ?> Vuqa. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Use-case tabs ──
        function showCase(btn, id) {
            document.querySelectorAll('.use-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.use-case-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('case-' + id).classList.add('active');
        }

        // ── Fade-up on scroll ──
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((e, i) => {
                if (e.isIntersecting) {
                    setTimeout(() => e.target.classList.add('visible'), i * 80);
                    observer.unobserve(e.target);
                }
            });
        }, { threshold: 0.15 });

        document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));
    </script>
</body>
</html>