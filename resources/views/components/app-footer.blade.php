@props([
    'variant' => 'app',
])

<footer class="app-footer app-footer--{{ $variant }}">
    <div class="app-footer__inner">
        <p class="app-footer__copy">
            © 2026 · WMS creado y desarrollado por Jorge Monge. Soluciones web corporativas para empresas que buscan control, eficiencia y trazabilidad.
        </p>
        <a
            href="https://www.jorgemonge.es"
            class="app-footer__link"
            target="_blank"
            rel="noopener noreferrer"
        >
            www.jorgemonge.es
        </a>
    </div>
</footer>
