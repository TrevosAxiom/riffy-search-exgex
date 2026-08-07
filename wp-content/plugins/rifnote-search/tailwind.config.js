/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './src/**/*.{js,jsx,ts,tsx}',
    './rifnote-search.php',
    './includes/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        rifnote: {
          red: '#d71920',
          dark: '#111827',
          soft: '#ffe8ea',
        },
      },
      borderRadius: {
        rifnote: '18px',
      },
    },
  },
  corePlugins: {
    preflight: false,
  },
  plugins: [],
};
