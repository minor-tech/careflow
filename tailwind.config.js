import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        // Enums that name a Tailwind class (TrackingTone) need scanning too.
        './app/Enums/*.php',
    ],

    theme: {
        extend: {
            // Design system palette (Sprint 1, Step 0). Mirrored as CSS variables in app.css.
            colors: {
                canvas: '#FFFFFF',
                primary: '#1E6B5C',
                ink: '#0B2E29',
                line: '#E8ECEA',
                wait: '#CE9E48',
                ok: '#4C7A5A',
                danger: '#B4423C',

                // Public marketing site only (never used in the dashboards).
                tint: {
                    light: '#E7F0EE', // section backgrounds
                    mid: '#CFE0D9', // hero background
                    mint: '#E4F5E9', // dashboard: active pill
                    'mint-soft': '#EAF7EE', // dashboard: hover
                },
                // Icon circles on the public site: a small, muted set, one per meaning.
                accent: {
                    teal: '#1E6B5C', // the primary; the single most important icon
                    blue: '#4E7FA8',
                    gold: '#CE9E48', // waiting and time
                    clay: '#BD8161',
                    sage: '#7EA184',
                    slate: '#6C7A80', // data and analytics
                },
            },
            boxShadow: {
                card: '0 2px 12px rgba(11, 46, 41, 0.06)',
                'card-hover': '0 8px 24px rgba(11, 46, 41, 0.10)',
                cta: '0 4px 14px rgba(30, 107, 92, 0.25)',
            },
            // One typeface everywhere (guest site and dashboards): Google Sans, loaded in layouts/head.blade.php.
            fontFamily: {
                sans: ['"Google Sans"', '-apple-system', '"Segoe UI"', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
            },
        },
    },

    plugins: [forms],
};
