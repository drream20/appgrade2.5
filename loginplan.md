# AppGrade Login Page — Landscape Desktop Redesign

Redesign the existing AppGrade login page for a modern, clean, and professional **landscape desktop layout**. The system is a digital grading platform for Concepcion Holy Cross College, Inc. The design should feel trustworthy, academic, minimal, and consistent with the school's navy-blue branding.

## 1. Layout and Structure

Create a centered, spacious login container with a two-column layout.

- **Left column (55%):** AppGrade branding, sign-in heading, login form, and supporting text.
- **Right column (45%):** A large display of the official Concepcion Holy Cross College anniversary emblem.
- Use a white background for the main container, subtle rounded corners, a thin light-gray border, and a soft shadow.
- Place the container in the center of a light blue-gray page background.
- For a typical 1440 × 900 desktop viewport, use a container approximately 1200–1300px wide, with comfortable internal spacing.
- Keep the content vertically balanced and aligned. Avoid excessive empty space, oversized elements, or unnecessary decorative components.

## 2. Left Column — Login Form

At the top, display the **AppGrade** wordmark in bold navy blue. Keep the branding simple and prominent.

Below it, include:

**Heading:** Sign in to your account

**Tagline:** Grades Made Simple. Results Made Powerful.

Use a clear visual hierarchy, with a bold heading and a smaller muted-blue tagline.

Create the following form fields:

**Student No. or Email**
- A clearly visible label above the input.
- A user icon on the left.
- Placeholder: `Enter your student number or email`
- White background, subtle light-blue border, rounded corners, and comfortable input height.

**Password**
- A clearly visible label above the input.
- A lock icon on the left.
- Placeholder: `Enter your password`
- An eye icon only on the right for showing or hiding the password.
- Do not display the text “Show” or “Hide.”
- Keep the eye icon vertically centered and aligned inside the input.

**Sign in button**
- Full width of the form.
- Dark navy-blue background with white, semibold text.
- Rounded corners and a subtle shadow.
- Include consistent hover, focus, and disabled states.

Below the button, display this helper text in muted blue-gray:

`Use your assigned student number or email and password.`

Maintain consistent spacing between labels, inputs, button, and helper text.

## 3. Right Column — School Identity

Create a dedicated visual panel with a very light blue background.

- Display the existing official Concepcion Holy Cross College anniversary emblem prominently in the center.
- Preserve the original image, proportions, details, and transparency where applicable.
- Use `object-fit: contain` to prevent distortion.
- Add subtle pale-blue gradients or restrained geometric shapes to give the panel depth.
- Keep the background decorations minimal so the official emblem remains the main visual focus.
- Do not recreate the school logo using text, replace it with a generated approximation, or stretch the image.

The right panel should feel balanced with the left login form and use the same navy-blue and light-blue visual language.

## 4. Design System

Use a consistent color palette:

- Primary navy: `#1E3A5F`
- Brand blue: `#23469A`
- Light blue: `#E8F0FC`
- Page background: `#F3F6FA`
- Main white: `#FFFFFF`
- Primary text: `#172033`
- Secondary text: `#75849A`
- Input border: `#D6DFEC`

Typography:
- Use Inter, Inter-compatible sans-serif, or the project's existing font.
- Use bold typography for the AppGrade wordmark and page heading.
- Use regular-weight typography for labels, placeholders, and helper text.
- Maintain consistent font sizes, line heights, and font weights.

Components:
- Use one consistent border-radius scale.
- Keep all input fields the same height and visual style.
- Align icons consistently and use a single outline-icon style.
- Use subtle borders and shadows rather than heavy effects.
- Avoid excessive gradients, glassmorphism, decorative cards, and unnecessary animations.

## 5. Functionality and Implementation Rules

This is a redesign of the existing login page, not a new authentication system.

- Preserve the current login functionality, form submission, validation, session handling, and authentication logic.
- Keep the password visibility toggle functional using the eye icon alone.
- Use the project's existing assets, form fields, and authentication flow.
- Do not hardcode example credentials into the actual login form.
- Do not introduce unrelated features or change database logic.
- Reuse existing CSS classes and components where practical.
- Avoid unnecessary dependencies or a complete rewrite of the existing project.

## 6. Scope

Focus on the **landscape desktop layout only**. Do not replace the existing mobile-specific design in this task. Keep the desktop implementation clean, maintainable, and consistent with the established AppGrade visual identity.

## Expected Result

A polished desktop login page with a spacious two-column layout, a clean sign-in form on the left, the official school anniversary emblem on the right, consistent navy-blue branding, aligned form controls, and a professional academic appearance.

Prioritize usability, visual consistency, accurate branding, and clean spacing over decorative effects.