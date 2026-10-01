// Import Firebase App
import { initializeApp } from "https://www.gstatic.com/firebasejs/12.2.1/firebase-app.js";

// Import Firebase Authentication
import { getAuth } from "https://www.gstatic.com/firebasejs/12.2.1/firebase-auth.js";

// Your Firebase configuration
const firebaseConfig = {
    apiKey: "AIzaSyC5Bk3X2xxCV-pxB95pIZ2rVJty_NnxufM",
    authDomain: "examination-seating-system.firebaseapp.com",
    projectId: "examination-seating-system",
    storageBucket: "examination-seating-system.firebasestorage.app",
    messagingSenderId: "615703813655",
    appId: "1:615703813655:web:4a41725993456b75530652"
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);

// Initialize Authentication
const auth = getAuth(app);

// Export Authentication
export { auth };