<?php
// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'database.php';
require_once 'otp_handler.php';

$otpHandler = new OTPHandler();
$showOTPForm = false;
$emailSent = false;
$emailError = false;

// Handle Form Submissions
if ($_POST) {
    $database = new Database();
    $db = $database->getConnection();
    
    // Step 1: Verify email/password
    // CHANGED: Checking for 'email' instead of 'username'
    if (isset($_POST['email']) && isset($_POST['password']) && !isset($_POST['otp'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];
        
        // CHANGED: Query searches by email column
        $query = "SELECT id, username, password, name, role, email FROM users WHERE email = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Store user data in session for OTP verification
            $_SESSION['pending_user'] = $user;
            $_SESSION['pending_email'] = $email; // CHANGED: Store email as pending identifier
            
            // Send OTP via email
            // CHANGED: First argument is now $email (identifier)
            $emailSent = $otpHandler->sendOTPEmail($email, $user['email'], $user['name']);
            
            if ($emailSent) {
                $showOTPForm = true;
                $success = "OTP sent to your email successfully!";
            } else {
                $error = "Failed to send OTP. Please try again.";
                $emailError = true;
            }
        } else {
            $error = "Invalid email or password"; // CHANGED: Error message
        }
    }
    
    // Step 2: Verify OTP
    if (isset($_POST['otp']) && isset($_SESSION['pending_user'])) {
        $otp = $_POST['otp'];
        // CHANGED: Retrieve pending email
        $verificationIdentifier = $_SESSION['pending_email'];
        
        if ($otpHandler->verifyOTP($verificationIdentifier, $otp)) {
            // OTP verified, complete login
            $user = $_SESSION['pending_user'];
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username']; // We still store username for the dashboard display
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            
            // Clean up
            unset($_SESSION['pending_user']);
            unset($_SESSION['pending_email']);
            
            header("Location: dashboard8.php");
            exit;
        } else {
            $error = "Invalid or expired OTP";
            $showOTPForm = true;
        }
    }
    
    // Resend OTP
    if (isset($_POST['resend_otp']) && isset($_SESSION['pending_user'])) {
        $user = $_SESSION['pending_user'];
        $email = $_SESSION['pending_email']; // CHANGED: Use pending email
        
        $emailSent = $otpHandler->sendOTPEmail($email, $user['email'], $user['name']);
        
        if ($emailSent) {
            $showOTPForm = true;
            $success = "New OTP sent successfully!";
        } else {
            $error = "Failed to resend OTP. Please try again.";
            $emailError = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>Microfinance Financial - Login</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            "brand-primary": "#059669",
            "brand-primary-hover": "#047857",
            "brand-background-main": "#F0FDF4",
            "brand-border": "#D1FAE5",
            "brand-text-primary": "#1F2937",
            "brand-text-secondary": "#4B5563",
          }
        }
      }
    }
  </script>

  <style>
    /* Custom CSS from styles.css */
    :root {
        --brand-primary: #059669;
        --brand-background-main: #F0FDF4;
    }
    body { 
        background-color: var(--brand-background-main);
        font-size: 16px; /* Explicit font size */
    }
    
    /* Login floating shapes - EVEN SMALLER */
    .shape {
        position: absolute;
        border-radius: 9999px;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0));
        animation: float 20s ease-in-out infinite;
    }
    @keyframes float {
        0% { transform: translateY(0px) translateX(0px); }
        50% { transform: translateY(-20px) translateX(15px); }
        100% { transform: translateY(0px) translateX(0px); }
    }
    @keyframes float-alt {
        0% { transform: translateY(0px) translateX(0px); }
        50% { transform: translateY(15px) translateX(-20px); }
        100% { transform: translateY(0px) translateX(0px); }
    }
    .shape-2 { animation-delay: 3s; }
    .shape-3 { animation: float-alt 25s ease-in-out infinite; animation-delay: 5s; }
    .shape-4 { animation: float-alt 15s ease-in-out infinite; animation-delay: 8s; }
    .shape-5 { animation-delay: 11s; }

    /* OTP Inputs */
    .otp-input {
        width: 3rem !important; 
        height: 3rem !important; 
        text-align: center; 
        font-size: 1.25rem !important; 
        font-weight: bold;
        border: 2px solid #D1D5DB; 
        border-radius: 0.5rem;
        transition: all 0.2s;
    }
    @media (max-width: 768px) {
        .otp-input {
            width: 2.5rem !important;
            height: 2.5rem !important;
            font-size: 1rem !important;
        }
    }
    
    .otp-input:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.2);
        outline: none;
    }
    
    /* Shake Animation for Errors */
    .shake { animation: shake 0.5s; }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
    }
    
    /* Better scaling for laptop screens */
    @media (min-width: 1024px) and (max-width: 1440px) {
        .login-container {
            transform: scale(0.9);
            transform-origin: center;
        }
        .shape {
            transform: scale(0.8);
        }
        
        /* Adjust left section for laptop screens */
        .left-section {
            padding-top: 2rem !important;
            padding-bottom: 2rem !important;
        }
    }
    
    /* Fix for mobile zoom issues */
    input, select, textarea {
        font-size: 16px !important; /* Prevents iOS zoom */
    }
  </style>
</head>

<body class="min-h-screen bg-brand-primary relative overflow-hidden">

  <div class="absolute inset-0 z-0 pointer-events-none">
    <!-- EVEN SMALLER SHAPES - MINIMAL SIZE -->
    <div class="shape w-32 h-32 top-[2%] left-[2%] bg-white/4"></div>
    <div class="shape shape-2 w-44 h-44 bottom-[5%] left-[12%] bg-white/4"></div>
    <div class="shape shape-3 w-32 h-32 top-[2%] right-[2%] bg-white/4"></div>
    <div class="shape shape-4 w-24 h-24 bottom-[10%] right-[10%] bg-white/4"></div>
    <div class="shape shape-5 w-20 h-20 top-[60%] left-[60%] -translate-x-1/2 -translate-y-1/2 bg-white/4"></div>
  </div>

  <div class="min-h-screen flex relative z-10 login-container">

    <section class="hidden lg:flex lg:w-1/2 items-center justify-center p-8 lg:p-12 text-white left-section">
      <div class="flex flex-col items-center w-full max-w-2xl py-2 lg:py-6">
        <div class="text-center mb-6">
          <div class="w-20 h-20 lg:w-24 lg:h-24 mx-auto bg-white/10 rounded-full flex items-center justify-center mb-4 backdrop-blur-sm">
             <i class="fas fa-users-cog text-2xl lg:text-3xl"></i>
          </div>
          <h1 class="text-2xl lg:text-3xl font-bold">Microfinance</h1>
          <p class="text-white/80 text-sm lg:text-base mt-1">FINANCIAL</p>
        </div>

        <div class="relative w-full max-w-xl h-56 lg:h-72 my-4 lg:my-6 flex items-center justify-center">
           <img src="assets/images/login/illustration-1.svg" alt="HR Illustration" class="login-svg absolute inset-0 w-full h-full object-contain transition-opacity duration-700 opacity-100" onerror="this.style.display='none'; document.getElementById('backup-icon').style.display='block'">
           <img src="assets/images/login/illustration-2.svg" alt="HR Illustration" class="login-svg absolute inset-0 w-full h-full object-contain transition-opacity duration-700 opacity-0">
           <div id="backup-icon" class="hidden text-7xl lg:text-8xl text-white/20"><i class="fas fa-chart-pie"></i></div>
        </div>

        <!-- MORE SPACING FOR QUOTE -->
        <div class="text-center mt-8 lg:mt-10 max-w-lg lg:max-w-xl px-4">
          <p class="italic text-white/90 text-sm lg:text-base leading-relaxed">
            "The strength of the team is each individual member. The strength of each member is the team."
          </p>
          <cite class="block text-right mt-4 text-white/60 text-xs lg:text-sm">- Phil Jackson</cite>
        </div>
      </div>
    </section>

    <section class="w-full lg:w-1/2 flex items-center justify-center p-4 lg:p-8">
      <div class="w-full max-w-sm lg:max-w-md bg-white/95 backdrop-blur-lg rounded-2xl shadow-2xl p-6 lg:p-8 transition-all duration-300">

        <?php if (isset($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4 lg:mb-6 rounded-r shadow-sm flex items-start animate-pulse">
                <i class="fas fa-exclamation-circle mt-1 mr-3"></i>
                <div>
                    <p class="font-medium">Error</p>
                    <p class="text-sm"><?php echo $error; ?></p>
                    <?php if ($emailError): ?>
                        <p class="text-xs mt-1 text-red-600">Check mailer.php configuration.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 lg:mb-6 rounded-r shadow-sm flex items-start">
                <i class="fas fa-check-circle mt-1 mr-3"></i>
                <div>
                    <p class="font-medium">Success</p>
                    <p class="text-sm"><?php echo $success; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$showOTPForm): ?>
        
        <div class="text-center mb-4 lg:mb-6">
          <h2 class="text-2xl lg:text-3xl font-bold text-brand-text-primary">Welcome Back!</h2>
          <p class="text-brand-text-secondary mt-1 text-sm lg:text-base">Please enter your credentials to sign in.</p>
        </div>

        <form id="login-form" method="POST" action="">
          <div class="relative mb-3 lg:mb-4">
            <label class="block text-sm font-medium text-gray-700" for="email">Email Address</label>
            <div class="mt-1 relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-envelope text-gray-400"></i>
              </div>
              <input 
                id="email" 
                name="email" 
                type="email" 
                placeholder="Enter your email"
                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                class="w-full pl-10 pr-3 py-2 lg:py-3 border border-gray-300 rounded-lg shadow-sm
                       focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-brand-primary
                       transition-all duration-200 text-base"
                required 
              />
            </div>
          </div>

          <div class="relative mb-3 lg:mb-4">
            <label class="block text-sm font-medium text-gray-700" for="password">Password</label>
            <div class="mt-1 relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-lock text-gray-400"></i>
              </div>

              <input 
                id="password" 
                name="password" 
                type="password" 
                placeholder="Enter your password"
                class="w-full pl-10 pr-10 py-2 lg:py-3 border border-gray-300 rounded-lg shadow-sm
                       focus:outline-none focus:ring-2 focus:ring-brand-primary focus:border-brand-primary
                       transition-all duration-200 text-base"
                required 
              />

              <div id="password-toggle"
                   class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer select-none transition-transform duration-150">
                <i id="eye-icon" class="fas fa-eye text-gray-400 hover:text-brand-primary transition-colors"></i>
              </div>
            </div>
          </div>

          <button id="sign-in-btn" type="submit" disabled
            class="w-full bg-brand-primary text-white font-bold py-2 lg:py-3 px-4 rounded-lg
                   transition-all duration-300 shadow-lg text-base
                   transform active:translate-y-0 active:scale-[0.99]
                   opacity-60 cursor-not-allowed">
            Sign In
          </button>

          <div class="mt-3 lg:mt-4 flex items-start gap-3">
            <input id="terms-check" type="checkbox"
              class="mt-1 h-4 w-4 text-brand-primary border-gray-300 rounded focus:ring-brand-primary transition cursor-pointer">
            <label for="terms-check" class="text-xs lg:text-sm text-gray-700 leading-relaxed select-none cursor-pointer">
              I agree to the
              <button id="terms-link" type="button"
                class="text-brand-primary hover:text-brand-primary-hover hover:underline transition-colors font-semibold">
                Terms and Conditions
              </button>
            </label>
          </div>
        </form>

        <?php else: ?>

        <div class="text-center mb-4 lg:mb-6">
          <div class="w-14 h-14 lg:w-16 lg:h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3 lg:mb-4">
            <i class="fas fa-envelope-open-text text-xl lg:text-2xl text-brand-primary"></i>
          </div>
          <h2 class="text-xl lg:text-2xl font-bold text-brand-text-primary">Verify It's You</h2>
          <p class="text-brand-text-secondary mt-2 text-xs lg:text-sm">
            We've sent a 6-digit code to your email associated with 
            <span class="font-semibold text-brand-primary"><?php echo htmlspecialchars($_SESSION['pending_email'] ?? 'your account'); ?></span>
          </p>
        </div>

        <form id="otpForm" method="POST" class="space-y-4 lg:space-y-6">
             <div class="flex justify-center gap-1 lg:gap-2">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric" autocomplete="one-time-code">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric">
                <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric">
            </div>
            
            <input type="hidden" name="otp" id="fullOtp">

            <button 
                type="button"
                id="verifyOtpBtn"
                class="w-full bg-brand-primary hover:bg-brand-primary-hover text-white font-bold py-2 lg:py-3 px-4 rounded-lg
                       transition-all duration-300 shadow-lg transform active:scale-[0.98] text-base">
                Verify OTP
            </button>
        </form>

        <div class="text-center mt-4 lg:mt-6">
             <form method="POST" class="inline">
                <input type="hidden" name="resend_otp" value="1">
                <p class="text-xs lg:text-sm text-gray-600">
                    Didn't receive the code? 
                    <button type="submit" class="text-brand-primary hover:underline font-semibold ml-1">Resend</button>
                </p>
            </form>
            <a href="index.php" class="block mt-3 lg:mt-4 text-xs text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-1"></i> Back to login
            </a>
        </div>

        <?php endif; ?>

        <div class="text-center mt-6 lg:mt-8 text-xs lg:text-sm">
          <p class="text-gray-500">&copy; 2026 Microfinance. All Rights Reserved.</p>
        </div>
      </div>
    </section>
  </div>

  <div id="terms-modal" class="fixed inset-0 hidden z-50">
    <div id="terms-backdrop" class="absolute inset-0 bg-black/40 opacity-0 transition-opacity duration-200"></div>

    <div class="relative mx-auto mt-12 lg:mt-24 w-[92%] max-w-lg bg-white rounded-2xl shadow-2xl border border-gray-100
                opacity-0 scale-95 translate-y-2 transition-all duration-200"
         id="terms-panel">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
        <div class="font-bold text-gray-800">Terms and Conditions</div>
        <button id="terms-close"
          class="w-9 h-9 rounded-xl hover:bg-gray-100 active:bg-gray-200 transition flex items-center justify-center">
          ✕
        </button>
      </div>

      <div class="p-5 min-h-[200px] lg:min-h-[240px] text-gray-600 text-sm overflow-y-auto max-h-[300px] lg:max-h-[400px]">
        <p class="mb-2"><strong>1. Usage Policy:</strong> By accessing this system, you agree to handle sensitive financial and HR data with strict confidentiality.</p>
        <p class="mb-2"><strong>2. Security:</strong> Do not share your password or OTP with anyone. The IT department will never ask for your password.</p>
        <p><strong>3. Monitoring:</strong> Activities on this dashboard are logged for security and auditing purposes.</p>
      </div>

      <div class="px-5 pb-5">
        <button id="terms-close-bottom"
          class="w-full bg-brand-primary hover:bg-brand-primary-hover text-white font-bold py-2 lg:py-3 rounded-lg
                 transition-all duration-200 active:scale-[0.99] text-base">
          Close
        </button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
        
      // --- Login: Password Toggle ---
      const pwd = document.getElementById("password");
      const toggle = document.getElementById("password-toggle");
      const eyeIcon = document.getElementById("eye-icon");

      if (pwd && toggle && eyeIcon) {
        toggle.addEventListener("click", () => {
          const isPassword = pwd.getAttribute("type") === "password";
          pwd.setAttribute("type", isPassword ? "text" : "password");
          
          if(isPassword) {
              eyeIcon.classList.remove('fa-eye');
              eyeIcon.classList.add('fa-eye-slash');
              eyeIcon.classList.add('text-brand-primary');
          } else {
              eyeIcon.classList.remove('fa-eye-slash');
              eyeIcon.classList.add('fa-eye');
              eyeIcon.classList.remove('text-brand-primary');
          }
        });
      }

      // --- Login: Terms Checkbox Enable ---
      const termsCheck = document.getElementById("terms-check");
      const signInBtn = document.getElementById("sign-in-btn");

      const setSignInEnabled = (enabled) => {
        if (!signInBtn) return;
        signInBtn.disabled = !enabled;
        if (enabled) {
          signInBtn.classList.remove("opacity-60", "cursor-not-allowed");
          signInBtn.classList.add("hover:bg-brand-primary-hover", "hover:shadow-xl", "hover:-translate-y-0.5");
        } else {
          signInBtn.classList.add("opacity-60", "cursor-not-allowed");
          signInBtn.classList.remove("hover:bg-brand-primary-hover", "hover:shadow-xl", "hover:-translate-y-0.5");
        }
      };

      if (termsCheck && signInBtn) {
        // Initial state
        setSignInEnabled(termsCheck.checked);
        termsCheck.addEventListener("change", () => setSignInEnabled(termsCheck.checked));
      }

      // --- Terms Modal Logic ---
      const termsLink = document.getElementById("terms-link");
      const termsModal = document.getElementById("terms-modal");
      const termsBackdrop = document.getElementById("terms-backdrop");
      const termsPanel = document.getElementById("terms-panel");
      const termsClose = document.getElementById("terms-close");
      const termsCloseBottom = document.getElementById("terms-close-bottom");

      const openTerms = () => {
        if (!termsModal || !termsBackdrop || !termsPanel) return;
        termsModal.classList.remove("hidden");
        requestAnimationFrame(() => {
          termsBackdrop.classList.remove("opacity-0");
          termsPanel.classList.remove("opacity-0", "scale-95", "translate-y-2");
          termsPanel.classList.add("opacity-100", "scale-100", "translate-y-0");
        });
      };

      const closeTerms = () => {
        if (!termsModal || !termsBackdrop || !termsPanel) return;
        termsBackdrop.classList.add("opacity-0");
        termsPanel.classList.add("opacity-0", "scale-95", "translate-y-2");
        termsPanel.classList.remove("opacity-100", "scale-100", "translate-y-0");
        setTimeout(() => termsModal.classList.add("hidden"), 200);
      };

      if (termsLink) termsLink.addEventListener("click", openTerms);
      if (termsBackdrop) termsBackdrop.addEventListener("click", closeTerms);
      if (termsClose) termsClose.addEventListener("click", closeTerms);
      if (termsCloseBottom) termsCloseBottom.addEventListener("click", closeTerms);

      // --- Illustration Carousel (Simple Fade) ---
      const svgs = Array.from(document.querySelectorAll(".login-svg"));
      if (svgs.length > 1) {
        let i = 0;
        setInterval(() => {
          svgs[i].classList.remove('opacity-100');
          svgs[i].classList.add('opacity-0');
          
          i = (i + 1) % svgs.length;
          
          svgs[i].classList.remove('opacity-0');
          svgs[i].classList.add('opacity-100');
        }, 4500);
      }
      
      // --- OTP Input Logic (Auto-tabbing) ---
      const otpInputs = document.querySelectorAll('.otp-input');
      const fullOtpInput = document.getElementById('fullOtp');
      const verifyOtpBtn = document.getElementById('verifyOtpBtn');
      const otpForm = document.getElementById('otpForm');

      if (otpInputs.length > 0) {
          // Focus first input
          otpInputs[0].focus();

          otpInputs.forEach((input, index) => {
              input.addEventListener('input', function(e) {
                  // Allow only numbers
                  this.value = this.value.replace(/[^0-9]/g, '');
                  
                  if (this.value.length >= 1) {
                      // Move to next
                      if (index < otpInputs.length - 1) {
                          otpInputs[index + 1].focus();
                      }
                  }
                  updateFullOtp();
              });

              input.addEventListener('keydown', function(e) {
                  // Handle Backspace
                  if (e.key === 'Backspace' && this.value === '') {
                      if (index > 0) {
                          otpInputs[index - 1].focus();
                      }
                  }
              });
              
              // Handle Paste
              input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                const digits = pastedData.replace(/\D/g, '').split(''); // Get only numbers
                
                if (digits.length > 0) {
                    otpInputs.forEach((inp, i) => {
                        if (digits[i]) {
                            inp.value = digits[i];
                        }
                    });
                    updateFullOtp();
                    // Focus the last filled input or the next empty one
                    const nextEmpty = Math.min(digits.length, otpInputs.length - 1);
                    otpInputs[nextEmpty].focus();
                }
              });
          });

          function updateFullOtp() {
              let code = '';
              otpInputs.forEach(input => code += input.value);
              if (fullOtpInput) fullOtpInput.value = code;
          }

          if (verifyOtpBtn) {
              verifyOtpBtn.addEventListener('click', function(e) {
                  updateFullOtp();
                  if (fullOtpInput.value.length === 6) {
                    otpForm.submit();
                  } else {
                      // Shake animation for invalid
                      otpForm.classList.add('shake');
                      setTimeout(() => otpForm.classList.remove('shake'), 500);
                  }
              });
          }
      }
      
      // --- Prevent zoom on mobile devices ---
      document.addEventListener('touchmove', function(e) {
          if(e.scale !== 1) e.preventDefault();
      }, { passive: false });
    });
  </script>
</body>
</html>