import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

const raisedSurface =
  "border shadow-[inset_0_-2px_0_rgba(15,23,42,0.24),0_1px_2px_rgba(15,23,42,0.14)] active:shadow-[inset_0_-1px_0_rgba(15,23,42,0.18)]"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all active:translate-y-px active:scale-[0.99] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default: `${raisedSurface} border-[color-mix(in_srgb,var(--primary)_72%,#0f172a)] bg-[color-mix(in_srgb,var(--primary)_94%,#0f172a)] text-white hover:border-[color-mix(in_srgb,var(--primary)_82%,#0f172a)] hover:bg-primary`,
        destructive: `${raisedSurface} border-[color-mix(in_srgb,var(--destructive)_72%,#0f172a)] bg-[color-mix(in_srgb,var(--destructive)_94%,#0f172a)] text-white hover:bg-destructive focus-visible:ring-destructive/40`,
        outline:
          "border border-input bg-transparent text-foreground hover:border-primary/40 hover:bg-accent hover:text-accent-foreground",
        secondary: `${raisedSurface} border-[color-mix(in_srgb,var(--secondary)_82%,var(--foreground))] bg-secondary text-secondary-foreground hover:bg-accent hover:text-accent-foreground`,
        ghost:
          "hover:bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        "default": "h-9 px-4 py-2 has-[>svg]:px-3",
        "sm": "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
        "icon": "size-9",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
