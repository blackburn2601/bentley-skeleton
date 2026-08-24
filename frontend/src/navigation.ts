import {
  KeyRound,
  LayoutDashboard,
  ScrollText,
  ShieldCheck,
  Users,
  UsersRound,
  type LucideIcon,
} from 'lucide-vue-next'

/**
 * The one declaration of what the admin area contains.
 *
 * The sidebar renders from this, and the router guard reads the same `permission` off route
 * meta. Two lists would drift, and the way they drift is a link to a page that 403s.
 *
 * `permission` here hides a link the caller cannot use. **It authorizes nothing** (INV-16) —
 * the endpoint behind each screen re-checks server-side, and the IDOR suite calls those
 * endpoints directly precisely because a hidden link is still a reachable route.
 */
export interface NavItem {
  label: string
  to: string
  icon: LucideIcon
  /** Class-level permission required to see this entry. Undefined means always shown. */
  permission?: string
  /**
   * Highlight only on an exact path match.
   *
   * RouterLink's active-class matches by prefix, so `/admin` would light up on `/admin/users`
   * too and two entries would look selected at once.
   */
  exact?: boolean
}

export interface NavSection {
  label: string
  items: NavItem[]
}

export const navigation: NavSection[] = [
  {
    label: 'Übersicht',
    items: [{ label: 'Dashboard', to: '/admin', icon: LayoutDashboard, exact: true }],
  },
  {
    label: 'Zugriff',
    items: [
      { label: 'Benutzer', to: '/admin/users', icon: Users, permission: 'user.read' },
      { label: 'Gruppen', to: '/admin/groups', icon: UsersRound, permission: 'group.read' },
      { label: 'Rollen', to: '/admin/roles', icon: ShieldCheck, permission: 'role.read' },
      {
        label: 'Berechtigungen',
        to: '/admin/permissions',
        icon: KeyRound,
        permission: 'permission.read',
      },
    ],
  },
  {
    label: 'Compliance',
    items: [{ label: 'Audit-Protokoll', to: '/admin/audit', icon: ScrollText, permission: 'audit.read' }],
  },
]
