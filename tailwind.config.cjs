const colors = require('tailwindcss/colors');

module.exports = {
  darkMode: 'class',
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
  ],
  theme: {
    extend: {
      zIndex: {
        9999: '9999',
      },
    }
  },
  plugins: [],
}
