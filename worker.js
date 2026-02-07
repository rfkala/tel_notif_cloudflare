export default {
  async fetch(request, env, ctx) {

    if (request.method !== "POST") {
      return new Response(JSON.stringify({
        ok: false,
        error: "Method not allowed"
      }), { status: 405 });
    }

    try {
      const body = await request.json();

      const { token, chat_id, text, parse_mode } = body;

      if (!token || !chat_id || !text) {
        return new Response(JSON.stringify({
          ok: false,
          error: "Missing parameters"
        }), { status: 400 });
      }

      const tgRes = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          chat_id,
          text,
          parse_mode: parse_mode || "HTML",
          disable_web_page_preview: true,
        }),
      });

      const data = await tgRes.text();

      return new Response(data, {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });

    } catch (err) {
      return new Response(JSON.stringify({
        ok: false,
        error: err.toString()
      }), { status: 500 });
    }
  }
};
