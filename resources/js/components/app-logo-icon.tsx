import type { SVGAttributes } from 'react';

// The template's neutral mark (a checked request card); each application
// replaces it, and public/favicon.*, with its own.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M10 4h20a6 6 0 0 1 6 6v20a6 6 0 0 1-6 6H10a6 6 0 0 1-6-6V10a6 6 0 0 1 6-6Zm0 3a3 3 0 0 0-3 3v20a3 3 0 0 0 3 3h20a3 3 0 0 0 3-3V10a3 3 0 0 0-3-3H10Zm17.06 8.94a1.5 1.5 0 0 1 0 2.12l-8.5 8.5a1.5 1.5 0 0 1-2.12 0l-4.5-4.5a1.5 1.5 0 1 1 2.12-2.12l3.44 3.44 7.44-7.44a1.5 1.5 0 0 1 2.12 0Z"
            />
        </svg>
    );
}
