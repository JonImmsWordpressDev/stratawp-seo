import { registerPlugin } from '@wordpress/plugins';
import Sidebar from './components/Sidebar';
import './style.scss';

registerPlugin( 'stratawp-seo', { render: Sidebar, icon: 'search' } );
