import { ref, watch } from 'vue';

// Inicjalizuj od razu, bez czekania na onMounted
const stored = typeof localStorage !== 'undefined' ? localStorage.getItem('theme') : null;
let initialDark = false;
if (stored === 'dark') {
  initialDark = true;
} else if (stored === 'light') {
  initialDark = false;
} else if (typeof window !== 'undefined') {
  initialDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
}

const isDarkMode = ref(initialDark);

function applyTheme() {
  const html = document.documentElement;
  console.log('applyTheme called, isDark:', isDarkMode.value);
  if (isDarkMode.value) {
    html.classList.add('dark');
    if (typeof localStorage !== 'undefined') localStorage.setItem('theme', 'dark');
  } else {
    html.classList.remove('dark');
    if (typeof localStorage !== 'undefined') localStorage.setItem('theme', 'light');
  }
  console.log('html.classList:', html.className);
}

// Aplikuj od razu
applyTheme();

// Obserwaj zmiany isDarkMode i aplikuj zawsze
watch(isDarkMode, () => {
  applyTheme();
});

function toggleTheme() {
  isDarkMode.value = !isDarkMode.value;
}

export function useTheme() {
  return { isDarkMode, toggleTheme };
}
