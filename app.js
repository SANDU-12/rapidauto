/* ============ API CONNECTION (PHP backend on WAMP) ============ */
// Same-origin relative path — works automatically once this project sits in
// C:\wamp64\www\rapidauto and you open it via http://localhost/rapidauto/
const API_BASE = "api";
let authToken = localStorage.getItem('authToken') || null;
let LIVE = false; // flipped to true after a successful health check below

async function api(path, opts={}){
  const res = await fetch(API_BASE + path, {
    ...opts,
    headers:{ 'Content-Type':'application/json', ...(authToken?{Authorization:'Bearer '+authToken}:{}) , ...(opts.headers||{}) },
    body: opts.body ? JSON.stringify(opts.body) : undefined
  });
  const data = await res.json().catch(()=>({}));
  if(!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}
// Quick check on load: is the PHP API actually reachable? (e.g. running under WAMP)
// Pages should `await apiReady` before deciding whether to call the API or fall back to demo data.
const apiReady = (async ()=>{
  try{ await api('/services.php'); LIVE = true; console.info('Connected to live PHP backend.'); }
  catch(e){ LIVE = false; console.warn('PHP backend not reachable — running in DEMO mode (localStorage). Open this project through WAMP (http://localhost/rapidauto/) to use the real database.'); }
  return LIVE;
})();

/* ============ IMAGE FALLBACK (never show a broken image icon) ============ */
function imgFallback(el, label){
  el.onerror = null;
  const text = (label||'Image').slice(0,22);
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="400" height="300" fill="#eef2f7"/><rect x="1" y="1" width="398" height="298" fill="none" stroke="#cbd5e1" stroke-width="2"/><text x="200" y="140" font-family="Arial" font-size="15" fill="#64748b" text-anchor="middle">🔧</text><text x="200" y="170" font-family="Arial" font-size="13" fill="#64748b" text-anchor="middle">${text}</text></svg>`;
  el.src = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

/* ============ VIDEO CARDS (thumbnail always loads; click plays embed; direct link always works) ============ */
function videoCard(youtubeId, title){
  return `<div class="video-card">
    <div class="yt-thumb" onclick="playYouTube(this,'${youtubeId}')" title="Play: ${title}">
      <img src="https://img.youtube.com/vi/${youtubeId}/hqdefault.jpg" alt="${title}" loading="lazy" onerror="imgFallback(this,'${title}')">
      <div class="yt-play-btn">▶</div>
    </div>
    <a href="https://www.youtube.com/watch?v=${youtubeId}" target="_blank" rel="noopener" class="yt-directlink">${title} — watch on YouTube ↗</a>
  </div>`;
}
function playYouTube(el, id){
  el.outerHTML = `<div class="yt-thumb"><iframe src="https://www.youtube.com/embed/${id}?autoplay=1&rel=0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen title="video"></iframe></div>`;
}

/* ============ SHARED NAV + FOOTER (injected on every page) ============ */
const PAGES = [
  ['index.html','Home'], ['about.html','About Us'], ['services.html','Services & Packages'], ['shop.html','Spare Parts'],
  ['blog.html','Blog & News'], ['reviews.html','Reviews'], ['book.html','Book Now'],
  ['track.html','Track Service'],
];
function currentPage(){ return (location.pathname.split('/').pop() || 'index.html'); }
function injectNav(){
  const cur = currentPage();
  document.body.insertAdjacentHTML('afterbegin', `
  <header><div class="nav">
    <a href="index.html" class="logo">RAPID<span>AUTO</span></a>
    <nav class="nav-links" id="navLinks">
      ${PAGES.map(([href,label])=>`<a href="${href}" class="${cur===href?'on':''}">${label}</a>`).join('')}
      <a href="#" class="btn btn-outline" onclick="openAuth('login');return false;">Login / Sign Up</a>
      <a href="checkout.html" class="btn btn-outline" style="position:relative;">🛒 Cart<span id="cartBadge" style="position:absolute;top:-8px;right:-8px;background:var(--accent);color:#0F172A;font-size:10px;font-weight:800;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;">0</span></a>
      <a href="book.html" class="btn btn-primary">Book Now</a>
    </nav>
    <button class="menu-toggle" onclick="document.getElementById('navLinks').classList.toggle('open')">☰</button>
  </div></header>`);
  updateCartBadge();
}
function injectFooter(){
  if(!document.getElementById('googleIdentityScript')){
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.defer = true; s.id = 'googleIdentityScript';
    document.head.appendChild(s);
  }
  if(!document.getElementById('fontAwesomeStyles')){
    const s = document.createElement('link');
    s.rel = 'stylesheet';
    s.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
    s.id = 'fontAwesomeStyles';
    document.head.appendChild(s);
  }
  document.body.insertAdjacentHTML('beforeend', `
  <footer><div class="foot-grid">
    <div><div class="logo" style="color:#fff;">RAPID<span style="color:var(--accent)">AUTO</span></div>
      <p style="font-size:13px;margin-top:10px;color:#94a3b8;">Rapid Auto Service Center - professional car wash & vehicle service, Sri Lanka.</p>
      <div class="payment-methods" aria-label="Accepted payment methods">
        <span class="payment-icon visa" title="Visa"><i class="fa-brands fa-cc-visa" aria-hidden="true"></i><b>VISA</b></span>
        <span class="payment-icon mastercard" title="Mastercard"><i class="fa-brands fa-cc-mastercard" aria-hidden="true"></i><b>Mastercard</b></span>
        <span class="payment-icon amex" title="American Express"><i class="fa-brands fa-cc-amex" aria-hidden="true"></i><b>AMEX</b></span>
        <span class="payment-icon paypal" title="PayPal"><i class="fa-brands fa-cc-paypal" aria-hidden="true"></i><b>PayPal</b></span>
      </div>
    </div>
    <div><h4>Quick Links</h4><a href="services.html">Services & Pricing</a><a href="book.html">Book Now</a><a href="track.html">Track Service</a><a href="about.html">About Us</a></div>
    <div><h4>Company</h4><a href="about.html">About Us</a><a href="reviews.html">Reviews</a><a href="blog.html">Blog & News</a><a href="about.html#contact">Contact</a></div>
    <div class="footer-contact"><h4>Contact</h4>
      <a class="contact-item" href="https://maps.google.com/?q=No+09%2F3C%2C+Hokandara+Road%2C+Kalogoda%2C+Pannipitiya%2C+Sri+Lanka" target="_blank" rel="noopener"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>No 09/3C, Hokandara Road, Kalogoda, Pannipitiya, Sri Lanka</span></a>
      <a class="contact-item" href="tel:+94776758532"><i class="fa-solid fa-phone" aria-hidden="true"></i><span>+94 77 675 8532</span></a>
      <a class="contact-item" href="mailto:info@rapidautoservice.lk"><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>info@rapidautoservice.lk</span></a>
    </div>
    <div class="foot-subscribe"><h4>Stay Connected</h4>
      <p>Get service updates and offers in your inbox.</p>
      <form onsubmit="subscribeNewsletter(event)">
        <label class="sr-only" for="newsletterEmail">Email address</label>
        <div class="newsletter-row"><input id="newsletterEmail" type="email" placeholder="Your email address" required><button type="submit" aria-label="Subscribe to newsletter"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button></div>
        <span class="newsletter-status" id="newsletterStatus" role="status"></span>
      </form>
      <h4 class="follow-title">Follow Us</h4>
      <div class="social-links" aria-label="Social media links">
        <a href="https://www.facebook.com/" target="_blank" rel="noopener" aria-label="Follow Rapid Auto on Facebook" title="Facebook"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>
        <a href="https://www.instagram.com/" target="_blank" rel="noopener" aria-label="Follow Rapid Auto on Instagram" title="Instagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>
        <a href="https://www.whatsapp.com/" target="_blank" rel="noopener" aria-label="Message Rapid Auto on WhatsApp" title="WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
      </div>
    </div>
  </div><div class="foot-bottom">© 2026 Rapid Auto Service Center. All rights reserved.</div></footer>
  <div class="modal-bg hide" id="authModal">
    <div class="modal" style="max-width:420px;">
      <button class="close-x" onclick="closeAuth()">✕</button>
      <div id="authBody"></div>
    </div>
  </div>`);
}
function subscribeNewsletter(event){
  event.preventDefault();
  const form = event.currentTarget;
  const status = form.querySelector('.newsletter-status');
  status.textContent = 'Thanks for subscribing!';
  form.reset();
}
function closeNav(){ const n=document.getElementById('navLinks'); if(n) n.classList.remove('open'); }

/* ============ SCROLL REVEAL ============ */
function initReveal(){
  const els = document.querySelectorAll('.reveal');
  const io = new IntersectionObserver((entries)=>{
    entries.forEach(e=>{ if(e.isIntersecting){ e.target.classList.add('in'); io.unobserve(e.target); } });
  },{threshold:.15});
  els.forEach(el=>io.observe(el));
}

/* ============ DATA LAYER ============ */
function db(key,fallback){ const v = localStorage.getItem(key); return v? JSON.parse(v): fallback; }
function setDb(key,val){ localStorage.setItem(key, JSON.stringify(val)); }
if(!localStorage.getItem('bookings')) setDb('bookings', []);
if(!localStorage.getItem('bookingSeq')) setDb('bookingSeq', 1000);

// Categorized packages, straight off the shop's price sign
const CATEGORIES = ["Motorcycle","Car","Jeep/Vessel","Three Wheeler","Van (Small)","Van (Large)","Lorry"];
const PRICE_LIST = [
 {name:"Motorcycle Wash Only",price:500,type:"Motorcycle",desc:"Exterior body wash for motorbikes & scooters."},
 {name:"Motorcycle Wash & Oil",price:600,type:"Motorcycle",desc:"Body wash plus a quick engine oil top-up."},
 {name:"Motorcycle Wash & Wax",price:700,type:"Motorcycle",desc:"Body wash with a protective wax finish."},
 {name:"Car Body Wash Only",price:800,type:"Car",desc:"Full exterior hand wash for sedans & hatchbacks."},
 {name:"Car Body Wash & Vacuum",price:1000,type:"Car",desc:"Exterior wash plus full interior vacuuming."},
 {name:"Car/Van Vacuum Only",price:600,type:"Car",desc:"Interior vacuum service only, no exterior wash."},
 {name:"Vessel/Jeep Body Wash",price:1000,type:"Jeep/Vessel",desc:"Exterior wash suited for jeeps & higher-clearance vehicles."},
 {name:"Vessel/Jeep Body Wash & Vacuum",price:1300,type:"Jeep/Vessel",desc:"Exterior wash plus interior vacuum for jeeps."},
 {name:"Three Wheel Body Wash",price:1100,type:"Three Wheeler",desc:"Complete body wash for three-wheelers."},
 {name:"Three Wheel Fully Wash & Oil",price:1800,type:"Three Wheeler",desc:"Full wash plus engine oil service for three-wheelers."},
 {name:"Van (Small) Body Wash",price:1000,type:"Van (Small)",desc:"Exterior wash for small vans."},
 {name:"Van (Small) Body Wash & Vacuum",price:1200,type:"Van (Small)",desc:"Exterior wash plus interior vacuum, small vans."},
 {name:"Van (Large) Body Wash",price:1500,type:"Van (Large)",desc:"Exterior wash for large vans."},
 {name:"Van (Large) Body Wash & Vacuum",price:2000,type:"Van (Large)",desc:"Exterior wash plus interior vacuum, large vans."},
 {name:"Lorry Body Wash (Dimo & other small lorry)",price:1600,type:"Lorry",desc:"Exterior wash for Dimo & similar small lorries."},
 {name:"Lorry Body Wash & Vacuum",price:2000,type:"Lorry",desc:"Full exterior wash plus cabin vacuum for lorries."},
];
const ADD_SERVICES = ["Engine Oil Change","Transmission Oil","Brake Service","Battery Replacement","Wheel Alignment","Wheel Balancing","Tyre Replacement","AC Service","Engine Diagnostics","Interior Detailing","Exterior Detailing","Wax Polishing","Ceramic Coating","Engine Cleaning","Pressure Wash","Underbody Wash","Steam Cleaning"];
const STAGES = ["Booking Confirmed","Vehicle Received","Inspection Started","Washing","Interior Cleaning","Vacuum","Wax","Quality Check","Payment Pending","Completed"];

/* ============ AUTH ============ */
// GOOGLE SIGN-IN SETUP: create your own OAuth Client ID at https://console.cloud.google.com/apis/credentials
// (Credentials -> Create Credentials -> OAuth Client ID -> Web application -> add http://localhost as an
// authorized JavaScript origin), then paste it below. Google sign-in is hidden until this is filled in.
const GOOGLE_CLIENT_ID = ""; // e.g. "123456789-abc.apps.googleusercontent.com"
const STAFF_ROLES = ['super_admin','manager','cashier','receptionist','mechanic','car_wash_staff','inventory_manager'];

let authMode = 'login'; // login | signup | forgot
function openAuth(mode){ authMode = mode || 'login'; document.getElementById('authModal').classList.remove('hide'); renderAuthBody(); }
function closeAuth(){ document.getElementById('authModal').classList.add('hide'); }
function switchAuthMode(mode){ authMode = mode; renderAuthBody(); }

function renderAuthBody(){
  const body = document.getElementById('authBody');
  if(authMode === 'forgot'){
    body.innerHTML = `
      <h3>Reset Your Password</h3>
      <p style="font-size:13px;color:var(--gray);margin-bottom:14px;">Enter your account email and we'll generate a reset link.</p>
      <div class="field"><label>Email</label><input id="authEmail" placeholder="you@email.com"></div>
      <button class="btn btn-primary" style="width:100%;" onclick="doForgotPassword()">Send Reset Link</button>
      <p style="text-align:center;font-size:12.5px;margin-top:14px;"><a href="#" style="color:var(--secondary);" onclick="switchAuthMode('login');return false;">← Back to login</a></p>`;
    return;
  }
  if(authMode === 'signup'){
    body.innerHTML = `
      <h3>Create Your Account</h3>
      <div class="form-grid">
        <div class="field"><label>First Name</label><input id="suFirst" placeholder="Nimal"></div>
        <div class="field"><label>Last Name</label><input id="suLast" placeholder="Perera"></div>
      </div>
      <div class="field"><label>Email</label><input id="suEmail" type="email" placeholder="you@email.com"></div>
      <div class="field"><label>Phone</label><input id="suPhone" placeholder="07X XXX XXXX"></div>
      <div class="field"><label>Password</label><input id="suPass" type="password" placeholder="At least 6 characters"></div>
      <div class="field"><label>Confirm Password</label><input id="suPass2" type="password" placeholder="Re-enter password"></div>
      <button class="btn btn-primary" style="width:100%;" onclick="doSignup()">Create Account</button>
      ${googleButtonHTML()}
      <p style="text-align:center;font-size:12.5px;margin-top:14px;">Already have an account? <a href="#" style="color:var(--secondary);" onclick="switchAuthMode('login');return false;">Log in</a></p>
      <p style="text-align:center;font-size:11px;color:var(--gray);margin-top:6px;">Staff & technician accounts are created by an administrator — contact your manager if you need one.</p>`;
    renderGoogleButton();
    return;
  }
  // default: single login for everyone — customers, admins, and technicians alike.
  // The server tells us who they are from their role, and we route them accordingly.
  body.innerHTML = `
    <h3>Log In</h3>
    <div class="field"><label>Email</label><input id="authEmail" type="email" placeholder="you@email.com"></div>
    <div class="field"><label>Password</label><input id="authPass" type="password" placeholder="••••••••"></div>
    <p style="text-align:right;margin-bottom:14px;"><a href="#" style="color:var(--secondary);font-size:12.5px;" onclick="switchAuthMode('forgot');return false;">Forgot password?</a></p>
    <button class="btn btn-primary" style="width:100%;" onclick="doLogin()">Log In</button>
    ${googleButtonHTML()}
    <p style="text-align:center;font-size:12.5px;margin-top:14px;">New here? <a href="#" style="color:var(--secondary);" onclick="switchAuthMode('signup');return false;">Create an account</a></p>
    <p style="text-align:center;font-size:11px;color:var(--gray);margin-top:10px;">Staff demo login: <b>admin@rapid.lk</b> / <b>admin123</b></p>`;
  renderGoogleButton();
}
function googleButtonHTML(){
  if(!GOOGLE_CLIENT_ID) return '';
  return `<div style="display:flex;align-items:center;gap:10px;margin:16px 0;color:var(--gray);font-size:12px;">
    <div style="flex:1;height:1px;background:var(--border);"></div>or<div style="flex:1;height:1px;background:var(--border);"></div></div>
    <div id="googleBtnHost" style="display:flex;justify-content:center;"></div>`;
}
function renderGoogleButton(){
  if(!GOOGLE_CLIENT_ID || !window.google || !google.accounts) return;
  try{
    google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: handleGoogleCredential });
    google.accounts.id.renderButton(document.getElementById('googleBtnHost'), { theme:'outline', size:'large', width:280 });
  }catch(e){ console.warn('Google Sign-In not available:', e.message); }
}
// Routes a successfully-authenticated user to the right place based on their role.
// Customers stay on the site; any staff/technician role goes to the admin dashboard.
function routeAfterLogin(user){
  if(STAFF_ROLES.includes(user.role)){
    setDb('isAdmin', true);
    location.href = 'admin.html';
  } else {
    setDb('isAdmin', false);
    setDb('customerName', user.firstName || user.email);
    closeAuth();
    alert('Welcome, ' + (user.firstName || user.email) + '!');
    location.reload();
  }
}
async function handleGoogleCredential(response){
  await apiReady;
  if(!LIVE){ alert('Connect the PHP backend (WAMP) to use Google sign-in.'); return; }
  try{
    const res = await api('/auth.php?action=google', { method:'POST', body:{ credential: response.credential } });
    authToken = res.token; localStorage.setItem('authToken', authToken);
    routeAfterLogin(res.user);
  }catch(e){ alert('Google sign-in failed: '+e.message); }
}
async function doLogin(){
  const email = document.getElementById('authEmail').value.trim();
  const pass = document.getElementById('authPass').value.trim();
  if(!email || !pass){ alert('Enter your email and password.'); return; }
  await apiReady;
  if(!LIVE){
    // Demo mode fallback (no database): only the known demo admin routes to the dashboard.
    if(email==='admin@rapid.lk' && pass==='admin123'){ setDb('isAdmin', true); location.href='admin.html'; return; }
    closeAuth(); alert('Welcome back, '+email+'! (Demo mode — connect the PHP backend for real accounts.)');
    return;
  }
  try{
    const res = await api('/auth.php?action=login', { method:'POST', body:{email,password:pass} });
    authToken = res.token; localStorage.setItem('authToken', authToken);
    routeAfterLogin(res.user);
  }catch(e){ alert(e.message); }
}
async function doSignup(){
  const firstName = document.getElementById('suFirst').value.trim();
  const lastName = document.getElementById('suLast').value.trim();
  const email = document.getElementById('suEmail').value.trim();
  const phone = document.getElementById('suPhone').value.trim();
  const pass = document.getElementById('suPass').value;
  const pass2 = document.getElementById('suPass2').value;
  if(!email || !pass){ alert('Email and password are required.'); return; }
  if(pass.length < 6){ alert('Password must be at least 6 characters.'); return; }
  if(pass !== pass2){ alert('Passwords do not match.'); return; }
  await apiReady;
  if(!LIVE){ closeAuth(); alert('Account created for '+email+'! (Demo mode — connect the PHP backend to persist real accounts.)'); return; }
  try{
    const res = await api('/auth.php?action=register', { method:'POST', body:{ firstName, lastName, email, phone, password:pass, confirmPassword:pass2 } });
    authToken = res.token; localStorage.setItem('authToken', authToken);
    routeAfterLogin(res.user);
  }catch(e){ alert(e.message); }
}
async function doForgotPassword(){
  const email = document.getElementById('authEmail').value.trim();
  if(!email){ alert('Enter your account email.'); return; }
  await apiReady;
  if(!LIVE){ alert('Connect the PHP backend (WAMP) to request a password reset.'); return; }
  try{
    const res = await api('/auth.php?action=forgot', { method:'POST', body:{email} });
    if(res.resetLink){
      alert('Demo mode (no email server configured): your reset link is —\n\n' + location.origin + location.pathname.replace(/[^/]*$/, '') + res.resetLink + '\n\nIn production this would be emailed to you instead.');
    } else {
      alert(res.message || 'If that email has an account, a reset link has been generated.');
    }
    switchAuthMode('login');
  }catch(e){ alert(e.message); }
}

/* ============ SPARE PARTS / SHOP DATA ============ */
const PRODUCTS = [
 {id:'p1',name:"Car Engine Oil 5W30 (4L)",price:4200,cat:"Fluids",img:"https://images.unsplash.com/photo-1635784063388-1ff609e42ac2?w=400&h=300&fit=crop"},
 {id:'p2',name:"Front Brake Pads (Set)",price:6500,cat:"Brakes",img:"https://images.unsplash.com/photo-1615906655593-ad0386982a0f?w=400&h=300&fit=crop"},
 {id:'p3',name:"12V Car Battery 45Ah",price:18500,cat:"Electrical",img:"https://images.unsplash.com/photo-1620891549027-942fdc95d3f5?w=400&h=300&fit=crop"},
 {id:'p4',name:"Air Filter (Universal)",price:1800,cat:"Filters",img:"https://images.unsplash.com/photo-1635784357262-04c8a1a5f5b8?w=400&h=300&fit=crop"},
 {id:'p5',name:"Wiper Blade Set",price:2400,cat:"Exterior",img:"https://images.unsplash.com/photo-1617886322168-72b886573c5f?w=400&h=300&fit=crop"},
 {id:'p6',name:"Spark Plug (Set of 4)",price:3200,cat:"Engine",img:"https://images.unsplash.com/photo-1607860108855-64acf2078ed9?w=400&h=300&fit=crop"},
 {id:'p7',name:"Car Wax Polish (500ml)",price:1500,cat:"Detailing",img:"https://images.unsplash.com/photo-1601362840469-51e4d8d58785?w=400&h=300&fit=crop"},
 {id:'p8',name:"Microfiber Cloth Pack (5)",price:900,cat:"Detailing",img:"https://images.unsplash.com/photo-1600861195091-690c92f1d2cd?w=400&h=300&fit=crop"},
 {id:'p9',name:"Headlight Bulb (H4, pair)",price:2100,cat:"Electrical",img:"https://images.unsplash.com/photo-1621007947382-bb3c3994e3fb?w=400&h=300&fit=crop"},
 {id:'p10',name:"Tyre Shine Spray (400ml)",price:1200,cat:"Detailing",img:"https://images.unsplash.com/photo-1520340356584-f9917d1eea6f?w=400&h=300&fit=crop"},
 {id:'p11',name:"Cabin AC Filter",price:1600,cat:"Filters",img:"https://images.unsplash.com/photo-1635784357262-04c8a1a5f5b8?w=400&h=300&fit=crop"},
 {id:'p12',name:"Car Floor Mats (Set of 4)",price:5200,cat:"Interior",img:"https://images.unsplash.com/photo-1592840331700-6a4b2b604c4d?w=400&h=300&fit=crop"},
];
function getCart(){ return db('cart', []); }
function setCart(c){ setDb('cart', c); updateCartBadge(); }
function addToCart(id, qty=1){
  const cart = getCart();
  const existing = cart.find(i=>i.id===id);
  if(existing) existing.qty += qty; else cart.push({id, qty});
  setCart(cart);
}
function removeFromCart(id){ setCart(getCart().filter(i=>i.id!==id)); }
function setCartQty(id, qty){
  const cart = getCart();
  const item = cart.find(i=>i.id===id);
  if(item){ item.qty = Math.max(1, qty); setCart(cart); }
}
function cartCount(){ return getCart().reduce((a,i)=>a+i.qty,0); }
function cartTotal(){
  return getCart().reduce((sum,i)=>{ const p=PRODUCTS.find(p=>p.id===i.id); return sum + (p?p.price*i.qty:0); },0);
}
function updateCartBadge(){
  const el = document.getElementById('cartBadge');
  if(el) el.textContent = cartCount();
}

/* ============ PAYMENT LOGO BADGES (generic, text-based — not reproducing brand artwork) ============ */
function paymentBadgesHTML(){
  return `<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <span style="font-family:Georgia,serif;font-style:italic;font-weight:800;font-size:15px;color:#1a1f71;border:1px solid var(--border);padding:5px 12px;border-radius:6px;background:#fff;">VISA</span>
    <span style="display:flex;align-items:center;border:1px solid var(--border);padding:5px 12px;border-radius:6px;background:#fff;font-weight:700;font-size:12px;color:#333;">
      <span style="width:16px;height:16px;border-radius:50%;background:#EB001B;display:inline-block;margin-right:-6px;"></span>
      <span style="width:16px;height:16px;border-radius:50%;background:#F79E1B;opacity:.85;display:inline-block;margin-right:6px;"></span>Mastercard</span>
    <span style="font-weight:800;font-size:14px;border:1px solid var(--border);padding:5px 12px;border-radius:6px;background:#fff;"><span style="color:#003087;">Pay</span><span style="color:#009cde;">Pal</span></span>
  </div>`;
}

document.addEventListener('DOMContentLoaded', ()=>{
  injectNav(); injectFooter();
  setTimeout(initReveal, 50);
});
